<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\EventInProgressException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

final class CallbackProcessor
{
    /** @var Config */
    private $config;

    /** @var SnapshotStore */
    private $snapshots;

    /** @var PdoEventStore */
    private $eventStore;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable|null */
    private $billingStateResolver;

    /** @var callable|null */
    private $refundCallbackHandler;

    /** @var callable|null */
    private $paymentApplier;

    /** @var callable */
    private $clock;

    /** @var callable|null */
    private $logger;

    public function __construct(
        Config $config,
        SnapshotStore $snapshots,
        PdoEventStore $eventStore,
        ?callable $clientFactory = null,
        ?callable $billingStateResolver = null,
        ?callable $refundCallbackHandler = null,
        ?callable $paymentApplier = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->snapshots = $snapshots;
        $this->eventStore = $eventStore;
        $this->clientFactory = $clientFactory;
        $this->billingStateResolver = $billingStateResolver;
        $this->refundCallbackHandler = $refundCallbackHandler;
        $this->paymentApplier = $paymentApplier;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
        $this->logger = $logger;
    }

    /**
     * Verify and process an incoming Paymos webhook delivery across Sandbox and Live.
     *
     * @param string $rawBody
     * @param string|array<string, mixed> $signatureOrHeaders
     * @param int|null $now
     * @return array{http_status: int, status: string, body: string, duplicate: bool, transaction: ?array<string, mixed>, environment: ?string, message: ?string}
     */
    public function handle(string $rawBody, $signatureOrHeaders, ?int $now = null): array
    {
        $signatureHeader = is_array($signatureOrHeaders)
            ? self::extractSignatureHeader($signatureOrHeaders)
            : trim((string) $signatureOrHeaders);

        $eventId = null;
        $refundDelivery = false;

        try {
            $verifier = new MultiEnvironmentWebhookVerifier($this->config->webhookSecrets(), $this->eventStore);
            $verified = $verifier->process($signatureHeader, $rawBody, $now ?? $this->now());
            $environment = $verified->environment();
            $event = $verified->event();
            $eventId = $event->id();

            $payloadArr = $event->toArray();
            $data = isset($payloadArr['data']) && is_array($payloadArr['data']) ? $payloadArr['data'] : array();

            if (strncmp($event->type(), 'withdrawal.', 11) === 0
                && (($data['type'] ?? '') === 'refund' || !empty($data['invoice_id']))) {
                $refundDelivery = true;
                if ($this->refundCallbackHandler === null) {
                    $this->safeRelease($eventId);
                    return $this->result(500, 'refund_handler_unavailable', 'Processing failed', false, null, $environment, 'Refund callback handler unavailable.');
                }
                call_user_func($this->refundCallbackHandler, $event, $environment);
                $this->commitOrThrow($eventId);
                return $this->result(200, 'refund_processed', 'OK', false, null, $environment);
            }

            if (!$event->isInvoiceEvent()) {
                $this->commitOrThrow($eventId);
                return $this->result(200, 'ignored_non_invoice', 'OK', false, null, $environment);
            }

            $this->assertPayloadEnvironment($event, $environment);
            $outcome = $this->applyVerifiedEvent($event, $environment, true, false);
            $this->commitOrThrow($eventId);

            return $this->result(
                200,
                $outcome['status'],
                'OK',
                false,
                $outcome['transaction'],
                $environment,
                $outcome['message']
            );
        } catch (DuplicateEventException $e) {
            $this->log('webhook.duplicate', array(), 'info');
            return $this->result(200, 'duplicate', 'OK', true, null, null, null);
        } catch (EventInProgressException $e) {
            if ($refundDelivery && $eventId !== null) {
                $this->safeRelease($eventId);
            }
            return $this->result(409, 'in_progress', 'In progress', false, null, null, $e->getMessage());
        } catch (SignatureMismatchException $e) {
            return $this->result(401, 'invalid_signature', 'Bad signature', false, null, null, $e->getMessage());
        } catch (TimestampSkewException $e) {
            return $this->result(401, 'timestamp_skew', 'Bad timestamp', false, null, null, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->safeRelease($eventId);
            $safe = $this->config->sanitize($e->getMessage());
            $this->log('webhook.config_error', array('error' => $safe), 'error');
            return $this->result(500, 'configuration_error', 'Configuration error', false, null, null, $safe);
        } catch (\PDOException $e) {
            $this->safeRelease($eventId);
            $safe = $this->config->sanitize($e->getMessage());
            $this->log('webhook.storage_error', array('error' => $safe), 'error');
            return $this->result(500, 'storage_error', 'Processing failed', false, null, null, $safe);
        } catch (\RuntimeException $e) {
            $this->safeRelease($eventId);
            $safe = $this->config->sanitize($e->getMessage());
            if ($e->getMessage() === 'refund_unbound_review') {
                $this->log('webhook.refund_unbound', array('error' => $safe), 'warning');
                return $this->result(409, 'refund_unbound', 'No matching refund', false, null, null, $safe);
            }
            $this->log('webhook.rejected', array('error' => $safe), 'warning');
            return $this->result(400, 'rejected', 'Processing failed', false, null, null, $safe);
        } catch (\Throwable $e) {
            $this->safeRelease($eventId);
            $safe = $this->config->sanitize($e->getMessage());
            $this->log('webhook.error', array('error' => $safe), 'error');
            return $this->result(500, 'error', 'Processing failed', false, null, null, $safe);
        }
    }

    /**
     * Apply a trusted invoice payload retrieved directly by cron Reconciler.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $snapshot
     */
    public function applyTrustedInvoice(array $invoice, array $snapshot, ?int $now = null): bool
    {
        $invoiceId = isset($invoice['invoice_id']) && is_scalar($invoice['invoice_id'])
            ? trim((string) $invoice['invoice_id'])
            : '';
        $status = isset($invoice['status']) && is_scalar($invoice['status'])
            ? strtolower(trim((string) $invoice['status']))
            : '';

        $eventType = self::eventTypeForStatus($status);
        if (!empty($invoice['paid_late_at']) && in_array($status, array('expired', 'underpaid'), true)) {
            $eventType = 'invoice.paid_late';
        }

        $event = new WebhookEvent(array(
            'event_id' => 'reconcile_' . $invoiceId . '_' . $status,
            'event_type' => $eventType,
            'version' => 1,
            'occurred_at' => $now ?? $this->now(),
            'data' => $invoice,
        ));

        $outcome = $this->applyEventToSnapshot($event, (string) $snapshot['environment'], $snapshot, false, true);
        return $outcome['applied'] === true;
    }

    /**
     * @return array{status: string, applied: bool, transaction: ?array<string, mixed>, message: ?string}
     */
    private function applyVerifiedEvent(
        WebhookEvent $event,
        string $environment,
        bool $reverseVerify,
        bool $fromCron
    ): array {
        $externalOrderId = $event->externalOrderId();
        $snapshot = null;
        if ($externalOrderId !== '') {
            $snapshot = $this->snapshots->findByExternalOrderId($externalOrderId);
        }
        if ($snapshot === null && $event->invoiceId() !== '') {
            $snapshot = $this->snapshots->findByInvoiceId($event->invoiceId());
        }
        if (!is_array($snapshot)) {
            throw new \RuntimeException('Paymos Blesta invoice snapshot was not found.');
        }

        return $this->applyEventToSnapshot($event, $environment, $snapshot, $reverseVerify, $fromCron);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{status: string, applied: bool, transaction: ?array<string, mixed>, message: ?string}
     */
    private function applyEventToSnapshot(
        WebhookEvent $event,
        string $environment,
        array $snapshot,
        bool $reverseVerify,
        bool $fromCron
    ): array {
        if (CheckoutService::isPendingIntent($snapshot)
            || strncmp((string) ($snapshot['paymos_invoice_id'] ?? ''), 'pending:', 8) === 0) {
            throw new \RuntimeException('Paymos invoice snapshot is still pending creation bind.');
        }

        $this->assertSnapshotMatchesEvent($snapshot, $event, $environment);

        $action = StatusMapper::invoiceAction($event->type(), $event->status());

        // invoice.paid_late arrives after a terminal unpaid status; note-only, never alters payment status.
        if ($action === StatusMapper::ACTION_NOTE_PAID_LATE) {
            if ($reverseVerify) {
                $this->verifyAgainstApi($event, $snapshot, $environment);
            }
            if (!empty($snapshot['paid_late_noted'])) {
                $this->log('webhook.paid_late_duplicate', array(
                    'invoice_id' => $event->invoiceId(),
                ), 'info');
                return array('status' => 'paid_late_already_noted', 'applied' => false, 'transaction' => null, 'message' => null);
            }

            $marker = $event->id() !== '' ? $event->id() : '1';
            $this->snapshots->markPaidLateNoted((string) $snapshot['external_order_id'], $marker);
            $this->snapshots->updateMetaFlag((string) $snapshot['external_order_id'], 'paid_late_note', 'Paymos: customer paid after invoice closed. Check service status and fulfil manually or refund.');
            $this->log('webhook.paid_late', array(
                'invoice_id' => $event->invoiceId(),
                'external_order_id' => $snapshot['external_order_id'],
            ), 'warning');

            return array('status' => 'paid_late_noted', 'applied' => false, 'transaction' => null, 'message' => null);
        }

        $recordedFinal = (string) ($snapshot['final_status'] ?? '');
        $recordedStatus = (string) ($snapshot['status'] ?? '');
        $rowIsFinal = StatusMapper::isFinalStatus($recordedFinal !== '' ? $recordedFinal : $recordedStatus);
        if ($rowIsFinal) {
            $this->log('webhook.stale_after_final', array(
                'invoice_id' => $event->invoiceId(),
                'recorded_status' => $recordedFinal !== '' ? $recordedFinal : $recordedStatus,
                'event_type' => $event->type(),
            ), 'info');
            return array('status' => 'ignored_stale', 'applied' => false, 'transaction' => null, 'message' => null);
        }

        $verifiedInvoice = null;
        if ($reverseVerify && $this->requiresReverseVerify($action)) {
            $verifiedInvoice = $this->verifyAgainstApi($event, $snapshot, $environment);
        }

        $billingState = $this->resolveBillingState($snapshot);
        if ($fromCron && !empty($billingState['deleted'])) {
            $this->snapshots->updateStatus((string) $snapshot['paymos_invoice_id'], SnapshotStore::STATUS_BLESTA_INVOICE_MISSING);
            $this->log('reconcile.blesta_invoice_missing', array('invoice_id' => $event->invoiceId()), 'warning');
            return array('status' => 'blesta_invoice_missing', 'applied' => false, 'transaction' => null, 'message' => null);
        }

        // Roll-back / double-payment guard: once Blesta invoice is Paid, ignore complete-or-downgrade events.
        if (!empty($billingState['paid']) && $this->isCompleteOrDowngrade($action)) {
            if (!$rowIsFinal) {
                $this->snapshots->updateStatus((string) $snapshot['paymos_invoice_id'], 'paid');
            }
            $this->log('webhook.already_paid_guarded', array(
                'invoice_id' => $event->invoiceId(),
                'event_type' => $event->type(),
            ), 'info');
            return array('status' => 'ignored_already_paid', 'applied' => false, 'transaction' => null, 'message' => null);
        }

        if ($action !== StatusMapper::ACTION_PAYMENT_COMPLETE) {
            $nextStatus = $event->status() !== '' ? $event->status() : $action;
            $this->snapshots->updateStatus((string) $snapshot['paymos_invoice_id'], $nextStatus);
            $this->log('webhook.status_updated', array(
                'invoice_id' => $event->invoiceId(),
                'status' => $nextStatus,
            ), 'info');
            return array('status' => $nextStatus, 'applied' => false, 'transaction' => null, 'message' => null);
        }

        $meta = SnapshotStore::decodeMeta(isset($snapshot['payload_meta']) ? (string) $snapshot['payload_meta'] : null);
        $currentAmount = isset($billingState['amount']) && is_scalar($billingState['amount']) && trim((string) $billingState['amount']) !== ''
            ? (string) $billingState['amount']
            : (string) $snapshot['amount'];
        $currentCurrency = isset($billingState['currency']) && is_scalar($billingState['currency']) && trim((string) $billingState['currency']) !== ''
            ? strtoupper(trim((string) $billingState['currency']))
            : strtoupper(trim((string) $snapshot['currency']));

        $expectedTotal = isset($meta['invoice_total']) && is_string($meta['invoice_total']) && $meta['invoice_total'] !== ''
            ? $meta['invoice_total']
            : (string) $snapshot['amount'];

        $safeCharge = AmountGuard::isSafeToComplete(
            (string) $snapshot['amount'],
            (string) $snapshot['currency'],
            (string) $snapshot['amount'],
            $currentCurrency,
            $event->orderAmount(),
            $event->orderCurrency()
        );
        $amountMatchesCurrent = AmountGuard::amountsEqual($expectedTotal, $currentAmount)
            || AmountGuard::amountsEqual((string) $snapshot['amount'], $currentAmount);

        if (!$safeCharge || !$amountMatchesCurrent) {
            $summary = AmountGuard::mismatchSummary(
                (string) $snapshot['amount'],
                (string) $snapshot['currency'],
                $currentAmount,
                $currentCurrency,
                $event->orderAmount(),
                $event->orderCurrency()
            );
            $this->snapshots->updateMetaFlag((string) $snapshot['external_order_id'], 'manual_review', true);
            $this->snapshots->updateMetaFlag((string) $snapshot['external_order_id'], 'manual_review_reason', 'amount_or_currency_mismatch');
            $this->log('webhook.manual_review', array(
                'invoice_id' => $event->invoiceId(),
                'summary' => $summary,
            ), 'warning');
            throw new \RuntimeException('Paymos payment amount or currency requires manual review.');
        }

        $txSource = is_array($verifiedInvoice) ? $verifiedInvoice : ($event->toArray()['data'] ?? array());
        $txHash = $this->extractTxHash(is_array($txSource) ? $txSource : array());
        $transactionId = $txHash !== '' ? $txHash : $event->invoiceId();

        $transaction = array(
            'client_id' => $snapshot['client_id'],
            'amount' => (string) $snapshot['amount'],
            'currency' => strtoupper(trim((string) $snapshot['currency'])),
            'invoices' => isset($meta['invoices']) && is_array($meta['invoices']) ? $meta['invoices'] : array(),
            'status' => 'approved',
            'reference_id' => (string) $snapshot['paymos_invoice_id'],
            'transaction_id' => $transactionId,
            'parent_transaction_id' => null,
        );

        if ($fromCron && $this->paymentApplier !== null) {
            call_user_func($this->paymentApplier, $transaction, $snapshot, $event);
        }

        $finalStatus = $event->status() !== '' ? $event->status() : 'paid';
        $this->snapshots->updateStatus((string) $snapshot['paymos_invoice_id'], $finalStatus, $transactionId);
        $this->log('webhook.payment_completed', array(
            'invoice_id' => $event->invoiceId(),
            'status' => $finalStatus,
            'transaction_id' => $transactionId,
        ), 'info');

        return array(
            'status' => $finalStatus,
            'applied' => true,
            'transaction' => $transaction,
            'message' => null,
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function verifyAgainstApi(WebhookEvent $event, array $snapshot, string $environment): array
    {
        $verification = (new InvoiceReverseVerifier($this->client($environment)))->verify($event, array(
            'invoice_id' => (string) $snapshot['paymos_invoice_id'],
            'project_id' => (string) $snapshot['project_id'],
            'external_order_id' => (string) $snapshot['external_order_id'],
            'amount' => (string) $snapshot['amount'],
            'currency' => (string) $snapshot['currency'],
            'environment' => $environment,
        ));

        if (!$verification->isVerified()) {
            $this->snapshots->updateMetaFlag((string) $snapshot['external_order_id'], 'manual_review', true);
            $this->snapshots->updateMetaFlag((string) $snapshot['external_order_id'], 'manual_review_reason', 'reverse_verify:' . $verification->reason());
            throw new \RuntimeException('Paymos reverse verification failed: ' . $verification->reason());
        }

        return $verification->invoice();
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function assertSnapshotMatchesEvent(array $snapshot, WebhookEvent $event, string $environment): void
    {
        if ((string) ($snapshot['environment'] ?? '') !== $environment) {
            throw new \RuntimeException('Paymos webhook environment does not match Blesta invoice snapshot.');
        }
        if ((string) ($snapshot['project_id'] ?? '') !== '' && $event->projectId() !== ''
            && (string) $snapshot['project_id'] !== $event->projectId()) {
            throw new \RuntimeException('Paymos webhook project_id does not match Blesta invoice snapshot.');
        }
        if ((string) ($snapshot['external_order_id'] ?? '') !== '' && $event->externalOrderId() !== ''
            && (string) $snapshot['external_order_id'] !== $event->externalOrderId()) {
            throw new \RuntimeException('Paymos webhook external_order_id does not match Blesta invoice snapshot.');
        }
        if ((string) ($snapshot['paymos_invoice_id'] ?? '') !== '' && $event->invoiceId() !== ''
            && (string) $snapshot['paymos_invoice_id'] !== $event->invoiceId()) {
            throw new \RuntimeException('Paymos webhook invoice_id does not match Blesta invoice snapshot.');
        }
    }

    private function assertPayloadEnvironment(WebhookEvent $event, string $environment): void
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }
        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }
        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    private function requiresReverseVerify(string $action): bool
    {
        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    private function isCompleteOrDowngrade(string $action): bool
    {
        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function resolveBillingState(array $snapshot): array
    {
        if ($this->billingStateResolver === null) {
            return array();
        }

        $state = call_user_func($this->billingStateResolver, $snapshot);
        return is_array($state) ? $state : array();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractTxHash(array $data): string
    {
        if (isset($data['payment']) && is_array($data['payment'])) {
            foreach (array('tx_hash', 'transaction_hash') as $field) {
                if (isset($data['payment'][$field]) && is_scalar($data['payment'][$field])) {
                    $val = trim((string) $data['payment'][$field]);
                    if ($val !== '') {
                        return $val;
                    }
                }
            }
            if (isset($data['payment']['transfers']) && is_array($data['payment']['transfers'])) {
                foreach ($data['payment']['transfers'] as $transfer) {
                    if (is_array($transfer) && isset($transfer['tx_hash']) && is_scalar($transfer['tx_hash'])) {
                        $val = trim((string) $transfer['tx_hash']);
                        if ($val !== '') {
                            return $val;
                        }
                    }
                }
            }
        }

        foreach (array('tx_hash', 'transaction_hash') as $field) {
            if (isset($data[$field]) && is_scalar($data[$field])) {
                $val = trim((string) $data[$field]);
                if ($val !== '') {
                    return $val;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $headers
     */
    public static function extractSignatureHeader(array $headers): string
    {
        foreach ($headers as $name => $value) {
            $normalized = strtolower(str_replace('_', '-', (string) $name));
            if ($normalized === 'x-webhook-signature' || $normalized === 'http-x-webhook-signature') {
                return is_scalar($value) ? trim((string) $value) : '';
            }
        }

        return '';
    }

    private static function eventTypeForStatus(string $status): string
    {
        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'invoice.confirming';
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                return 'invoice.underpaid_waiting';
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return $status === 'paid_over' ? 'invoice.paid_over' : 'invoice.paid';
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'invoice.underpaid';
            case StatusMapper::ACTION_CANCEL_ORDER:
                return $status === 'expired' ? 'invoice.expired' : 'invoice.cancelled';
        }

        return 'invoice.updated';
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $this->config, $environment);
        }

        return new Client($this->config->clientConfig($environment));
    }

    private function commitOrThrow(string $eventId): void
    {
        if (!$this->eventStore->commit($eventId)) {
            throw new \RuntimeException('Failed to commit webhook event deduplication receipt.');
        }
    }

    private function safeRelease(?string $eventId): void
    {
        try {
            $this->eventStore->release($eventId);
        } catch (\Throwable $e) {
            // Preserve the original failure classification; reservation expires for retry.
        }
    }

    /**
     * @param array<string, mixed>|null $transaction
     * @return array{http_status: int, status: string, body: string, duplicate: bool, transaction: ?array<string, mixed>, environment: ?string, message: ?string}
     */
    private function result(
        int $httpStatus,
        string $status,
        string $body,
        bool $duplicate = false,
        ?array $transaction = null,
        ?string $environment = null,
        ?string $message = null
    ): array {
        return array(
            'http_status' => $httpStatus,
            'status' => $status,
            'body' => $body,
            'duplicate' => $duplicate,
            'transaction' => $transaction,
            'environment' => $environment,
            'message' => $message,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $event, array $context, string $level = 'info'): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $event, $context, $level);
        }
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
