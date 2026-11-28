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

    /**
     * function (array $snapshot, string $title, string $body): ?bool — writes one native
     * admin-visible Blesta note; throws on failure, returns false when it cannot be written.
     *
     * @var callable|null
     */
    private $noteWriter;

    /**
     * function (string $transactionId): ?bool — whether Blesta already stores this gateway
     * transaction (true / false), null when it cannot tell.
     *
     * @var callable|null
     */
    private $transactionLookup;

    public function __construct(
        Config $config,
        SnapshotStore $snapshots,
        PdoEventStore $eventStore,
        ?callable $clientFactory = null,
        ?callable $billingStateResolver = null,
        ?callable $refundCallbackHandler = null,
        ?callable $paymentApplier = null,
        ?callable $clock = null,
        ?callable $logger = null,
        ?callable $noteWriter = null,
        ?callable $transactionLookup = null
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
        $this->noteWriter = $noteWriter;
        $this->transactionLookup = $transactionLookup;
    }

    /**
     * Blesta transactions are keyed by transaction_id + gateway (GatewayPayments::processNotification).
     */
    public static function blestaTransactionLookup(): callable
    {
        return static function (string $transactionId): ?bool {
            if (trim($transactionId) === '' || !class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                return null;
            }

            $host = new \stdClass();
            \Loader::loadModels($host, array('Transactions', 'GatewayManager'));
            if (!isset($host->Transactions) || !method_exists($host->Transactions, 'getByTransactionId')) {
                return null;
            }

            $gatewayId = null;
            if (isset($host->GatewayManager) && method_exists($host->GatewayManager, 'getByClass')) {
                $companyId = class_exists('Configure') && method_exists('Configure', 'get')
                    ? (int) (\Configure::get('Blesta.company_id') ?: 1)
                    : 1;
                $gateways = $host->GatewayManager->getByClass('paymos', $companyId);
                if (is_array($gateways) && isset($gateways[0]->id) && is_numeric($gateways[0]->id)) {
                    $gatewayId = (int) $gateways[0]->id;
                }
            }

            return (bool) $host->Transactions->getByTransactionId($transactionId, null, $gatewayId);
        };
    }

    /**
     * Native admin-visible note for paid_late and manual review: a sticky note on the Blesta
     * client profile (Clients::addNote), written by the system (no staff member).
     */
    public static function blestaClientNoteWriter(): callable
    {
        return static function (array $snapshot, string $title, string $body): bool {
            $clientId = isset($snapshot['client_id']) && is_numeric($snapshot['client_id']) ? (int) $snapshot['client_id'] : 0;
            if ($clientId <= 0 || !class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                return false;
            }

            $host = new \stdClass();
            \Loader::loadModels($host, array('Clients'));
            if (!isset($host->Clients) || !is_object($host->Clients) || !method_exists($host->Clients, 'addNote')) {
                return false;
            }

            $noteId = $host->Clients->addNote($clientId, null, array(
                'title' => $title,
                'description' => $body,
                'stickied' => 1,
            ));
            $errors = method_exists($host->Clients, 'errors') ? $host->Clients->errors() : false;
            if (!$noteId || !empty($errors)) {
                throw new \RuntimeException('paymos_native_note_failed');
            }

            return true;
        };
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
            // Owner-fenced: drops only this delivery's own reservation (e.g. the payment lock was
            // busy), never the reservation of the delivery that is still running.
            $this->safeRelease($eventId);
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

        // The webhook and the cron reconciler share one lock per Paymos invoice and re-read the
        // row under it: whoever comes second sees the payment the first one recorded.
        return $this->snapshots->withPaymentLock(
            (string) $snapshot['paymos_invoice_id'],
            function () use ($event, $environment, $snapshot, $reverseVerify, $fromCron): array {
                $fresh = $this->snapshots->findByExternalOrderId((string) $snapshot['external_order_id']);
                if (!is_array($fresh)) {
                    throw new \RuntimeException('Paymos Blesta invoice snapshot was not found.');
                }

                return $this->applyLocked($event, $environment, $fresh, $reverseVerify, $fromCron);
            }
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{status: string, applied: bool, transaction: ?array<string, mixed>, message: ?string}
     */
    private function applyLocked(
        WebhookEvent $event,
        string $environment,
        array $snapshot,
        bool $reverseVerify,
        bool $fromCron
    ): array {
        $this->assertSnapshotMatchesEvent($snapshot, $event, $environment);

        $action = StatusMapper::invoiceAction($event->type(), $event->status());

        // invoice.paid_late arrives after a terminal unpaid status; note-only, never alters payment status.
        if ($action === StatusMapper::ACTION_NOTE_PAID_LATE) {
            if ($reverseVerify) {
                $this->verifyAgainstApi($event, $snapshot, $environment);
            }

            $alreadyNoted = !empty($snapshot['paid_late_noted']);
            if (!$alreadyNoted) {
                $marker = $event->id() !== '' ? $event->id() : '1';
                $this->snapshots->markPaidLateNoted((string) $snapshot['external_order_id'], $marker);
                $this->snapshots->updateMetaFlag((string) $snapshot['external_order_id'], 'paid_late_note', 'Paymos: customer paid after invoice closed. Check service status and fulfil manually or refund.');
                $this->log('webhook.paid_late', array(
                    'invoice_id' => $event->invoiceId(),
                    'external_order_id' => $snapshot['external_order_id'],
                ), 'warning');
            }

            // One native note per invoice; a failed write is retried by the next delivery or cron run.
            $noteAdded = $this->addNativeNoteOnce($snapshot, 'paid_late', 'Paymos.note.paid_late.title', 'Paymos.note.paid_late.body');
            if ($alreadyNoted && !$noteAdded) {
                $this->log('webhook.paid_late_duplicate', array(
                    'invoice_id' => $event->invoiceId(),
                ), 'info');
                return array('status' => 'paid_late_already_noted', 'applied' => false, 'transaction' => null, 'message' => null);
            }

            return array('status' => 'paid_late_noted', 'applied' => false, 'transaction' => null, 'message' => null);
        }

        $recordedFinal = (string) ($snapshot['final_status'] ?? '');
        $recordedStatus = (string) ($snapshot['status'] ?? '');
        $rowIsFinal = StatusMapper::isFinalStatus($recordedFinal !== '' ? $recordedFinal : $recordedStatus);
        if ($rowIsFinal) {
            $replay = $fromCron ? null : $this->replayRecordedPayment($event, $snapshot, $environment, $action, $reverseVerify);
            if ($replay !== null) {
                // The payment was recorded here but Blesta may not have stored its transaction
                // (failed delivery). Blesta updates the transaction with the same transaction_id
                // instead of adding one, and refuses to apply more than the transaction holds.
                $this->log('webhook.payment_replayed', array(
                    'invoice_id' => $event->invoiceId(),
                    'transaction_id' => $replay['transaction_id'],
                ), 'info');
                return array('status' => $recordedFinal !== '' ? $recordedFinal : $recordedStatus, 'applied' => false, 'transaction' => $replay, 'message' => null);
            }

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
        // The recorded Paymos status is left as it is: writing "paid" here would make the real
        // Paymos payment that may still arrive look like a stale event.
        if (!empty($billingState['paid']) && $this->isCompleteOrDowngrade($action)) {
            if ($action === StatusMapper::ACTION_PAYMENT_COMPLETE) {
                // Money did arrive in Paymos for an invoice Blesta already closed another way.
                $this->snapshots->updateStatus(
                    (string) $snapshot['paymos_invoice_id'],
                    $event->status() !== '' ? $event->status() : 'paid'
                );
                $this->flagManualReview($snapshot, 'blesta_invoice_already_paid', 'Paymos.note.manual_review.already_paid', $event);
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
            $this->log('webhook.manual_review', array(
                'invoice_id' => $event->invoiceId(),
                'summary' => $summary,
            ), 'warning');
            $this->flagManualReview($snapshot, 'amount_or_currency_mismatch', 'Paymos.note.manual_review.amount_mismatch', $event);
            throw new \RuntimeException('Paymos payment amount or currency requires manual review.');
        }

        $txSource = is_array($verifiedInvoice) ? $verifiedInvoice : ($event->toArray()['data'] ?? array());
        $txHash = $this->extractTxHash(is_array($txSource) ? $txSource : array());
        $transactionId = $txHash !== '' ? $txHash : $event->invoiceId();

        $transaction = $this->buildTransaction($snapshot, $transactionId);

        if ($fromCron && $this->paymentApplier !== null) {
            call_user_func($this->paymentApplier, $transaction, $snapshot, $event);
        }

        $finalStatus = $event->status() !== '' ? $event->status() : 'paid';
        $this->snapshots->updateStatus((string) $snapshot['paymos_invoice_id'], $finalStatus, $transactionId);
        if ($fromCron && $this->paymentApplier !== null) {
            // The applier confirmed Blesta holds the transaction: no later delivery replays it.
            $this->snapshots->updateMetaFlag((string) $snapshot['external_order_id'], 'blesta_transaction_confirmed', true);
        }
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
     * The Blesta transaction for a recorded Paymos payment (the shape validate() returns).
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buildTransaction(array $snapshot, string $transactionId): array
    {
        $meta = SnapshotStore::decodeMeta(isset($snapshot['payload_meta']) ? (string) $snapshot['payload_meta'] : null);

        return array(
            'client_id' => $snapshot['client_id'],
            'amount' => (string) $snapshot['amount'],
            'currency' => strtoupper(trim((string) $snapshot['currency'])),
            'invoices' => isset($meta['invoices']) && is_array($meta['invoices']) ? $meta['invoices'] : array(),
            'status' => 'approved',
            'reference_id' => (string) $snapshot['paymos_invoice_id'],
            'transaction_id' => $transactionId,
            'parent_transaction_id' => null,
        );
    }

    /**
     * A redelivered payment event for a payment already recorded here: hand Blesta the same
     * transaction again so a delivery that failed after the record is not lost.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>|null
     */
    private function replayRecordedPayment(
        WebhookEvent $event,
        array $snapshot,
        string $environment,
        string $action,
        bool $reverseVerify
    ): ?array {
        $recorded = strtolower(trim((string) ($snapshot['final_status'] ?? $snapshot['status'] ?? '')));
        $transactionId = trim((string) ($snapshot['transaction_id'] ?? ''));
        $meta = SnapshotStore::decodeMeta(isset($snapshot['payload_meta']) ? (string) $snapshot['payload_meta'] : null);
        if ($action !== StatusMapper::ACTION_PAYMENT_COMPLETE
            || !in_array($recorded, array('paid', 'paid_over'), true)
            || strtolower(trim($event->status())) !== $recorded
            || $transactionId === ''
            || !empty($meta['blesta_transaction_confirmed'])
            || $this->transactionLookup === null) {
            return null;
        }

        // Replay only what Blesta does not hold: a transaction it knows (possibly refunded or
        // voided by the admin since) must not be overwritten, and its payment email not re-sent.
        $known = call_user_func($this->transactionLookup, $transactionId);
        if ($known === true) {
            $this->snapshots->updateMetaFlag((string) $snapshot['external_order_id'], 'blesta_transaction_confirmed', true);
            return null;
        }
        if ($known !== false) {
            return null;
        }

        if ($reverseVerify) {
            $this->verifyAgainstApi($event, $snapshot, $environment);
        }

        return $this->buildTransaction($snapshot, $transactionId);
    }

    /**
     * Mark the payment for manual review and leave the admin one native note about it.
     *
     * @param array<string, mixed> $snapshot
     */
    private function flagManualReview(array $snapshot, string $reason, string $bodyKey, WebhookEvent $event): void
    {
        $externalOrderId = (string) $snapshot['external_order_id'];
        $this->snapshots->updateMetaFlag($externalOrderId, 'manual_review', true);
        $this->snapshots->updateMetaFlag($externalOrderId, 'manual_review_reason', $reason);

        try {
            $this->addNativeNoteOnce($snapshot, 'manual_review', 'Paymos.note.manual_review.title', $bodyKey, array(
                '{amount}' => $event->orderAmount() !== '' ? $event->orderAmount() : (string) $snapshot['amount'],
                '{currency}' => $event->orderCurrency() !== '' ? $event->orderCurrency() : (string) $snapshot['currency'],
            ));
        } catch (\Throwable $e) {
            $this->log('webhook.native_note_failed', array(
                'invoice_id' => (string) $snapshot['paymos_invoice_id'],
                'error' => $this->config->sanitize($e->getMessage()),
            ), 'warning');
        }
    }

    /**
     * Write one native Blesta note of $kind for this payment (idempotent via payload_meta).
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, string> $vars
     * @return bool true when a note was written now
     */
    private function addNativeNoteOnce(array $snapshot, string $kind, string $titleKey, string $bodyKey, array $vars = array()): bool
    {
        if ($this->noteWriter === null) {
            return false;
        }

        $externalOrderId = (string) $snapshot['external_order_id'];
        $fresh = $this->snapshots->findByExternalOrderId($externalOrderId);
        $current = is_array($fresh) ? $fresh : $snapshot;
        $meta = SnapshotStore::decodeMeta(isset($current['payload_meta']) ? (string) $current['payload_meta'] : null);
        $flag = 'native_note_' . $kind;
        if (!empty($meta[$flag])) {
            return false;
        }

        $locale = Translation::detectLocale();
        $vars = array_merge(array(
            '{paymos_invoice_id}' => (string) $current['paymos_invoice_id'],
            '{amount}' => (string) $current['amount'],
            '{currency}' => (string) $current['currency'],
        ), $vars);
        $title = strtr(Translation::text($titleKey, $locale), $vars);
        $body = strtr(Translation::text($bodyKey, $locale), $vars);

        if (call_user_func($this->noteWriter, $current, $title, $body) === false) {
            return false;
        }

        $this->snapshots->updateMetaFlag($externalOrderId, $flag, $this->now());
        $this->log('webhook.native_note', array(
            'invoice_id' => (string) $current['paymos_invoice_id'],
            'kind' => $kind,
        ), 'info');

        return true;
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
