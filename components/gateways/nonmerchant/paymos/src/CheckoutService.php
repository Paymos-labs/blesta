<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Exception\NotFoundException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoicePresentation;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\InvoiceReplacementResult;
use Paymos\Plugin\StatusMapper;

final class CheckoutService
{
    public const STATUS_CREATING = 'creating';

    /**
     * A create the server refused for good (a 4xx other than 408/429), or answered with a
     * final invoice this attempt never created. The server holds no payable invoice of ours
     * for the key: the row is never replayed, read or cancelled; the next attempt cuts a new key.
     */
    public const STATUS_CREATE_FAILED = 'create_failed';

    public const MAX_PRODUCTS = 50;

    /** Fresh keys tried in one checkout when the server answers with someone else's final invoice. */
    private const MAX_FRESH_ATTEMPTS = 3;

    /** @var Config */
    private $config;

    /** @var SnapshotStore */
    private $snapshots;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable|null */
    private $lineItemsResolver;

    /** @var callable */
    private $clock;

    /** @var callable|null */
    private $logger;

    /**
     * function (string $transactionId): ?bool — whether Blesta stores the transaction of a
     * recorded payment (see CallbackProcessor::blestaTransactionLookup()).
     *
     * @var callable|null
     */
    private $transactionLookup;

    public function __construct(
        Config $config,
        SnapshotStore $snapshots,
        ?callable $clientFactory = null,
        ?callable $lineItemsResolver = null,
        ?callable $clock = null,
        ?callable $logger = null,
        ?callable $transactionLookup = null
    ) {
        $this->config = $config;
        $this->snapshots = $snapshots;
        $this->clientFactory = $clientFactory;
        $this->lineItemsResolver = $lineItemsResolver;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
        $this->logger = $logger;
        $this->transactionLookup = $transactionLookup;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isPendingIntent(array $row): bool
    {
        return isset($row['status']) && (string) $row['status'] === self::STATUS_CREATING;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isFailedCreate(array $row): bool
    {
        return isset($row['status']) && (string) $row['status'] === self::STATUS_CREATE_FAILED;
    }

    /**
     * A recorded completed payment (paid / paid_over). It is never reused for a new payment
     * and never closed: the next payment of the same reference gets its own attempt.
     *
     * @param array<string, mixed> $row
     */
    private static function isCompletedPayment(array $row): bool
    {
        $recorded = (string) ($row['final_status'] ?? $row['status'] ?? '');
        return StatusMapper::invoiceAction('', strtolower(trim($recorded))) === StatusMapper::ACTION_PAYMENT_COMPLETE;
    }

    /**
     * A 4xx answer other than 408/429 refuses the request itself: the server made no
     * invoice for it and the same request will be refused again. 408, 429, 5xx and
     * transport errors leave the result unknown.
     */
    private static function isDefinitiveRejection(ApiException $e): bool
    {
        $status = $e->statusCode();
        return $status >= 400 && $status < 500 && $status !== 408 && $status !== 429;
    }

    /**
     * Create or reuse a Paymos hosted checkout invoice with crash-safe STATUS_CREATING,
     * fail-closed InvoiceReplacement, InvoiceRenewal, paid_late guard, and products[].
     *
     * @param float|int|string $amount
     * @param array<int, array<string, mixed>> $invoiceAmounts
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function createCheckout(
        string $clientId,
        $amount,
        string $currency,
        array $invoiceAmounts = array(),
        array $options = array()
    ): array {
        $normalizedAmount = AmountGuard::normalizeDecimal($amount);
        if ($normalizedAmount === null || $normalizedAmount === '0' || $normalizedAmount[0] === '-') {
            throw new \InvalidArgumentException('Invoice amount must be a positive decimal.');
        }

        $normalizedCurrency = strtoupper(trim($currency));
        if (preg_match('/\A[A-Z]{3}\z/', $normalizedCurrency) !== 1) {
            throw new \InvalidArgumentException('Currency must be a 3-letter ISO 4217 code.');
        }

        $invoices = $this->normalizeInvoices($invoiceAmounts);
        $platformRef = $this->buildPlatformRef($clientId, $invoices, $normalizedAmount, $normalizedCurrency);
        $primaryInvoiceId = count($invoices) === 1 && isset($invoices[0]['id']) ? (int) $invoices[0]['id'] : null;
        $safeClientId = $this->normalizeClientId($clientId);

        $existing = $this->snapshots->findLatestByPlatformRef($platformRef);

        if (is_array($existing) && self::isPendingIntent($existing)) {
            $existing = $this->recoverPendingIntent($existing, $safeClientId, $invoices, $options);
        }

        if (is_array($existing) && !empty($existing['paid_late_noted'])) {
            $this->blockPaidLate($existing, 'Paymos invoice was paid late and requires manual review.');
        }

        // A recorded payment Blesta has not stored yet (its delivery is being retried) keeps its
        // checkout: a second invoice now would let the buyer pay the same amount twice.
        if (is_array($existing) && self::isCompletedPayment($existing) && $this->paymentAwaitsBlesta($existing)) {
            $this->log('checkout.payment_awaits_blesta', array(
                'paymos_invoice_id' => (string) $existing['paymos_invoice_id'],
            ), 'info');
            return array(
                'payment_url' => (string) $existing['payment_url'],
                'paymos_invoice_id' => (string) $existing['paymos_invoice_id'],
                'external_order_id' => (string) $existing['external_order_id'],
                'amount' => $normalizedAmount,
                'currency' => $normalizedCurrency,
                'reused' => true,
            );
        }

        // A completed payment or a refused create is history, not an invoice to reuse or close.
        $startsNewAttempt = is_array($existing)
            && (self::isFailedCreate($existing) || self::isCompletedPayment($existing));

        if (is_array($existing)
            && !$startsNewAttempt
            && $this->snapshotMatches($existing, $normalizedAmount, $normalizedCurrency)
            && $this->keepsExistingInvoice($existing)) {
            return array(
                'payment_url' => (string) $existing['payment_url'],
                'paymos_invoice_id' => (string) $existing['paymos_invoice_id'],
                'external_order_id' => (string) $existing['external_order_id'],
                'amount' => $normalizedAmount,
                'currency' => $normalizedCurrency,
                'reused' => true,
            );
        }

        if (is_array($existing) && !$startsNewAttempt) {
            $this->closeBeforeReplacing($existing);
        }

        $renewAttempt = is_array($existing) ? ((int) ($existing['renewal_attempt'] ?? 0) + 1) : 0;
        $products = $this->extractProducts($invoices, $normalizedCurrency, $options);

        $meta = array(
            'invoices' => $invoices,
        );
        if (isset($options['invoice_total']) && is_scalar($options['invoice_total'])) {
            $normTotal = AmountGuard::normalizeDecimal((string) $options['invoice_total']);
            if ($normTotal !== null && $normTotal !== '0' && $normTotal[0] !== '-') {
                $meta['invoice_total'] = $normTotal;
            }
        }
        $encodedMeta = json_encode($meta, JSON_UNESCAPED_SLASHES);

        for ($try = 1; ; $try++) {
            $externalOrderId = $this->buildExternalOrderId($platformRef, $renewAttempt);

            $payload = array(
                'project_id' => $this->config->projectId(),
                'amount' => $normalizedAmount,
                'currency' => $normalizedCurrency,
                'external_order_id' => $externalOrderId,
            );
            if ($safeClientId !== '') {
                $payload['client_id'] = $safeClientId;
            }
            if ($products !== null) {
                $payload['products'] = $products;
            }

            // Persist STATUS_CREATING intent BEFORE sending POST /v1/invoices.
            $now = $this->now();
            $intent = array(
                'external_order_id' => $externalOrderId,
                'paymos_invoice_id' => self::pendingInvoiceId($externalOrderId),
                'platform_ref' => $platformRef,
                'blesta_invoice_id' => $primaryInvoiceId,
                'client_id' => $safeClientId !== '' ? $safeClientId : null,
                'payload_meta' => is_string($encodedMeta) ? $encodedMeta : null,
                'amount' => $normalizedAmount,
                'currency' => $normalizedCurrency,
                'environment' => $this->config->environment(),
                'project_id' => $this->config->projectId(),
                'payment_url' => '',
                'transaction_id' => null,
                'status' => self::STATUS_CREATING,
                'final_status' => null,
                'paid_late_noted' => null,
                'expires_at' => null,
                'renewal_attempt' => $renewAttempt,
                'created_at' => $now,
                'updated_at' => $now,
            );
            $this->snapshots->save($intent);

            try {
                $response = $this->client($this->config->environment())->invoices()->create($payload);
            } catch (ApiException $e) {
                if (self::isDefinitiveRejection($e)) {
                    $this->markCreateFailed($intent);
                }
                throw $e;
            }

            // This key was never sent by this installation: a final invoice in the answer belongs
            // to someone else (another installation or an emptied database on the same project).
            if ($this->responseIsFinal($response)) {
                $this->markCreateFailed($intent);
                $this->log('checkout.foreign_final_invoice', array(
                    'external_order_id' => $externalOrderId,
                    'status' => (string) ($response['status'] ?? ''),
                ), 'warning');
                if ($try >= self::MAX_FRESH_ATTEMPTS) {
                    throw new \RuntimeException('Paymos answered a new checkout with a final invoice of another reference.');
                }
                $renewAttempt++;
                continue;
            }

            $bound = $this->bindCreatedInvoice($intent, $response);

            return array(
                'payment_url' => (string) $bound['payment_url'],
                'paymos_invoice_id' => (string) $bound['paymos_invoice_id'],
                'external_order_id' => (string) $bound['external_order_id'],
                'amount' => $normalizedAmount,
                'currency' => $normalizedCurrency,
                'reused' => false,
            );
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function responseIsFinal(array $response): bool
    {
        $status = isset($response['status']) && is_scalar($response['status'])
            ? strtolower(trim((string) $response['status']))
            : '';
        return $status !== '' && StatusMapper::isFinalStatus($status);
    }

    /**
     * Close a creating intent; a row another request bound meanwhile is returned as it is.
     *
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function markCreateFailed(array $intent): array
    {
        $externalOrderId = (string) $intent['external_order_id'];
        if ($this->snapshots->markCreateFailed($externalOrderId, (string) $intent['paymos_invoice_id'], self::STATUS_CREATING, self::STATUS_CREATE_FAILED)) {
            $intent['status'] = self::STATUS_CREATE_FAILED;
            return $intent;
        }

        $current = $this->snapshots->findByExternalOrderId($externalOrderId);
        return is_array($current) ? $current : $intent;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function paymentAwaitsBlesta(array $row): bool
    {
        if ($this->transactionLookup === null || trim((string) ($row['payment_url'] ?? '')) === '') {
            return false;
        }
        $meta = SnapshotStore::decodeMeta(isset($row['payload_meta']) ? (string) $row['payload_meta'] : null);
        $transactionId = trim((string) ($row['transaction_id'] ?? ''));
        if (!empty($meta['blesta_transaction_confirmed']) || $transactionId === '') {
            return false;
        }

        $known = call_user_func($this->transactionLookup, $transactionId);
        if ($known === true) {
            $this->snapshots->updateMetaFlag((string) $row['external_order_id'], 'blesta_transaction_confirmed', true);
            return false;
        }

        return true;
    }

    public function formatSafeError(\Throwable $e, string $locale = 'en_us'): string
    {
        if ($e instanceof InvoiceReplacementBlockedException) {
            return Translation::text('Paymos.!error.replacement_blocked', $locale);
        }

        if ($e instanceof ApiException) {
            $this->log('checkout.api_error', array(
                'status' => $e->statusCode(),
                'code' => $e->errorCode(),
                'field' => $e->field(),
                'detail' => $this->config->sanitize((string) ($e->detail() ?? $e->getMessage())),
            ), 'error');

            $detail = $e->detail();
            if ($detail !== null && trim($detail) !== '' && Translation::isEnglish($locale)) {
                $msg = $this->config->sanitize(trim($detail));
                $field = $e->field();
                if ($field !== null && trim($field) !== '') {
                    $msg .= ' (' . trim($field) . ')';
                }
                return $msg;
            }

            return Translation::text('Paymos.!error.payment_cannot_be_used', $locale);
        }

        $this->log('checkout.error', array(
            'error' => $this->config->sanitize($e->getMessage()),
        ), 'error');

        return Translation::text('Paymos.!error.checkout.failed', $locale);
    }

    /**
     * Replay a persisted STATUS_CREATING intent with its exact original identity
     * (external_order_id, project_id, amount, currency, environment).
     *
     * @param array<string, mixed> $intent
     * @param array<int, array<string, mixed>> $invoices
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function recoverPendingIntent(
        array $intent,
        string $safeClientId,
        array $invoices,
        array $options
    ): array {
        $payload = array(
            'project_id' => (string) $intent['project_id'],
            'amount' => (string) $intent['amount'],
            'currency' => (string) $intent['currency'],
            'external_order_id' => (string) $intent['external_order_id'],
        );

        $storedClient = isset($intent['client_id']) && is_scalar($intent['client_id'])
            ? $this->normalizeClientId((string) $intent['client_id'])
            : $safeClientId;
        if ($storedClient !== '') {
            $payload['client_id'] = $storedClient;
        }

        $products = $this->extractProducts($invoices, (string) $intent['currency'], $options);
        if ($products !== null) {
            $payload['products'] = $products;
        }

        try {
            $response = $this->client((string) $intent['environment'])->invoices()->create($payload);
        } catch (ApiException $e) {
            if (!self::isDefinitiveRejection($e)) {
                throw $e;
            }
            // Refused for good: the server holds no invoice for this key. Close the intent so
            // the checkout goes on to the next key with the current amount.
            $this->log('checkout.create_refused', array(
                'external_order_id' => (string) $intent['external_order_id'],
                'status' => $e->statusCode(),
                'code' => $e->errorCode(),
            ), 'warning');

            return $this->markCreateFailed($intent);
        }

        return $this->bindCreatedInvoice($intent, $response);
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function bindCreatedInvoice(array $intent, array $response): array
    {
        $paymosInvoiceId = isset($response['invoice_id']) && is_scalar($response['invoice_id'])
            ? trim((string) $response['invoice_id'])
            : '';
        $paymentUrl = isset($response['payment_url']) && is_scalar($response['payment_url'])
            ? trim((string) $response['payment_url'])
            : '';

        if ($paymosInvoiceId === '' || $paymentUrl === '' || !Config::isValidHttpsUrl($paymentUrl, true)) {
            throw new \RuntimeException('Paymos invoice create response is missing invoice_id or valid HTTPS payment_url.');
        }

        $rawStatus = isset($response['status']) && is_scalar($response['status'])
            ? strtolower(trim((string) $response['status']))
            : '';
        // If a recovered idempotent create returns an already-terminal status,
        // keep the local snapshot non-final so webhook/reconciler can still book it in Blesta.
        $storedStatus = ($rawStatus === '' || StatusMapper::isFinalStatus($rawStatus))
            ? 'created'
            : $rawStatus;

        $expiresAt = isset($response['expires_at']) && is_numeric($response['expires_at'])
            ? (int) $response['expires_at']
            : null;

        $row = $intent;
        $row['paymos_invoice_id'] = $paymosInvoiceId;
        $row['payment_url'] = $paymentUrl;
        $row['status'] = $storedStatus;
        $row['final_status'] = null;
        $row['expires_at'] = $expiresAt;
        $row['updated_at'] = $this->now();

        $this->snapshots->save($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotMatches(array $row, string $amount, string $currency): bool
    {
        return AmountGuard::amountsEqual((string) ($row['amount'] ?? ''), $amount)
            && strtoupper(trim((string) ($row['currency'] ?? ''))) === strtoupper(trim($currency))
            && (string) ($row['project_id'] ?? '') === $this->config->projectId()
            && (string) ($row['environment'] ?? '') === $this->config->environment()
            && trim((string) ($row['payment_url'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function keepsExistingInvoice(array $row): bool
    {
        $now = $this->now();
        // The stored expires_at is stale once the buyer confirms a network (the server moves the
        // deadline without a webhook): only the recorded status may short-circuit; a passed local
        // deadline is a reason to read the invoice back below.
        if (InvoiceRenewal::isRequired(array('status' => (string) ($row['final_status'] ?? $row['status'] ?? '')), $now)) {
            return false;
        }

        $recordedStatus = (string) ($row['final_status'] ?? $row['status'] ?? '');
        if (StatusMapper::isFinalStatus($recordedStatus)) {
            return true;
        }

        try {
            $invoice = $this->client((string) $row['environment'])->invoices()->get((string) $row['paymos_invoice_id']);
        } catch (NotFoundException $e) {
            return false;
        } catch (\Throwable $e) {
            return true;
        }

        if (is_array($invoice) && !empty($invoice['paid_late_at'])) {
            $marker = is_scalar($invoice['paid_late_at']) ? (string) $invoice['paid_late_at'] : '1';
            $this->snapshots->markPaidLateNoted((string) $row['external_order_id'], $marker);
            $row['paid_late_noted'] = $marker;
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        if (!is_array($invoice) || !InvoiceRenewal::isRequired($invoice, $now)) {
            return true;
        }

        $liveStatus = isset($invoice['status']) && is_scalar($invoice['status'])
            ? strtolower(trim((string) $invoice['status']))
            : '';
        if ($liveStatus !== '') {
            $this->snapshots->updateStatus((string) $row['paymos_invoice_id'], $liveStatus);
        }

        return false;
    }

    /**
     * Fail-closed close of an existing Paymos invoice before issuing a replacement.
     *
     * @param array<string, mixed> $row
     */
    private function closeBeforeReplacing(array $row): void
    {
        if (!empty($row['paid_late_noted'])) {
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        $environment = (string) $row['environment'];
        $recorded = (string) ($row['final_status'] ?? $row['status'] ?? '');
        $observed = new \stdClass();
        $observed->invoice = null;

        $replacement = new InvoiceReplacement(function () use ($environment, $observed) {
            $client = $this->client($environment);

            return new class($client, $observed) {
                /** @var Client */
                private $client;
                /** @var \stdClass */
                private $observed;

                public function __construct(Client $client, \stdClass $observed)
                {
                    $this->client = $client;
                    $this->observed = $observed;
                }

                public function invoices(): object
                {
                    return new class($this->client->invoices(), $this->observed) {
                        /** @var object */
                        private $invoices;
                        /** @var \stdClass */
                        private $observed;

                        public function __construct(object $invoices, \stdClass $observed)
                        {
                            $this->invoices = $invoices;
                            $this->observed = $observed;
                        }

                        /** @return array<string, mixed> */
                        public function cancel(string $invoiceId, string $reason): array
                        {
                            $response = $this->invoices->cancel($invoiceId, $reason);
                            if (is_array($response)
                                && (!empty($response['paid_late_at'])
                                    || !is_array($this->observed->invoice)
                                    || empty($this->observed->invoice['paid_late_at']))) {
                                $this->observed->invoice = $response;
                            }
                            return $response;
                        }

                        /** @return array<string, mixed> */
                        public function get(string $invoiceId): array
                        {
                            $response = $this->invoices->get($invoiceId);
                            if (is_array($response)
                                && (!empty($response['paid_late_at'])
                                    || !is_array($this->observed->invoice)
                                    || empty($this->observed->invoice['paid_late_at']))) {
                                $this->observed->invoice = $response;
                            }
                            return $response;
                        }
                    };
                }
            };
        });

        $result = $replacement->close((string) $row['paymos_invoice_id'], $recorded);

        if (is_array($observed->invoice) && !empty($observed->invoice['paid_late_at'])) {
            $marker = is_scalar($observed->invoice['paid_late_at'])
                ? (string) $observed->invoice['paid_late_at']
                : '1';
            $this->snapshots->markPaidLateNoted((string) $row['external_order_id'], $marker);
            $row['paid_late_noted'] = $marker;
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        if ($result->isClosed()) {
            if ($result->status() !== '' && $result->status() !== $recorded) {
                $this->snapshots->updateStatus((string) $row['paymos_invoice_id'], $result->status());
            }
            return;
        }

        $this->snapshots->updateMetaFlag((string) $row['external_order_id'], 'manual_review', true);
        $this->snapshots->updateMetaFlag((string) $row['external_order_id'], 'manual_review_reason', $result->reason());

        $this->log('checkout.replacement_blocked', array(
            'paymos_invoice_id' => $result->invoiceId(),
            'status' => $result->status(),
            'reason' => $result->reason(),
            'detail' => $this->config->sanitize($result->detail()),
        ), 'warning');

        throw new InvoiceReplacementBlockedException($result);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function blockPaidLate(array $row, string $detail): void
    {
        $result = InvoiceReplacementResult::blocked(
            isset($row['paymos_invoice_id']) ? (string) $row['paymos_invoice_id'] : '',
            isset($row['status']) ? (string) $row['status'] : '',
            InvoiceReplacementResult::REASON_PAID,
            $detail
        );

        $this->log('checkout.paid_late_blocked', array(
            'paymos_invoice_id' => $result->invoiceId(),
            'status' => $result->status(),
            'reason' => $result->reason(),
            'detail' => $detail,
        ), 'warning');

        throw new InvoiceReplacementBlockedException($result);
    }

    /**
     * Extract 1..50 validated checkout products[] items from Blesta line items.
     * Falls back to null (amount-only) if empty, >50, or any line item is invalid.
     *
     * @param array<int, array<string, mixed>> $invoices
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>|null
     */
    private function extractProducts(array $invoices, string $currency, array $options): ?array
    {
        $rawItems = null;
        if (isset($options['line_items']) && is_array($options['line_items'])) {
            $rawItems = $options['line_items'];
        } elseif ($this->lineItemsResolver !== null) {
            try {
                $resolved = call_user_func($this->lineItemsResolver, $invoices, $currency);
                if (is_array($resolved)) {
                    $rawItems = $resolved;
                }
            } catch (\Throwable $e) {
                $rawItems = null;
            }
        }

        if (!is_array($rawItems) || count($rawItems) === 0 || count($rawItems) > self::MAX_PRODUCTS) {
            return null;
        }

        $candidates = array();
        foreach ($rawItems as $item) {
            if (is_object($item)) {
                $item = get_object_vars($item);
            }
            if (!is_array($item)) {
                return null;
            }

            if (array_key_exists('title', $item)) {
                $title = is_string($item['title']) ? $item['title'] : null;
                $description = isset($item['description']) && is_string($item['description'])
                    ? $item['description']
                    : null;
            } else {
                $rawDesc = isset($item['description']) && is_string($item['description'])
                    ? $item['description']
                    : null;
                if ($rawDesc === null) {
                    $title = null;
                    $description = null;
                } else {
                    $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $rawDesc));
                    $title = array_shift($lines);
                    $rest = trim(implode("\n", $lines));
                    $description = $rest !== '' ? $rest : null;
                }
            }

            $rawQty = array_key_exists('quantity', $item)
                ? $item['quantity']
                : (array_key_exists('qty', $item) ? $item['qty'] : 1);
            $quantity = $this->normalizeProductQuantity($rawQty);
            if ($quantity === null) {
                return null;
            }

            $rawUnit = array_key_exists('unit_amount', $item)
                ? $item['unit_amount']
                : (array_key_exists('amount', $item) ? $item['amount'] : null);
            $unitAmount = AmountGuard::normalizeDecimal($rawUnit);
            // A free line (domain promo, 0.00 setup fee) is a valid product; only negative lines
            // (discounts, credits) cannot be expressed and fall back to amount-only.
            if ($unitAmount === null || $unitAmount[0] === '-') {
                return null;
            }

            $candidates[] = array(
                'title' => $title,
                'description' => $description,
                'quantity' => $quantity,
                'unit_amount' => $unitAmount,
            );
        }

        return InvoicePresentation::normalizeProducts($candidates);
    }

    /**
     * @param mixed $rawQty
     */
    private function normalizeProductQuantity($rawQty): ?int
    {
        if (is_int($rawQty)) {
            return $rawQty >= 1 && $rawQty <= 2147483647 ? $rawQty : null;
        }

        $normalized = AmountGuard::normalizeDecimal($rawQty);
        if ($normalized === null || strpos($normalized, '.') !== false || $normalized[0] === '-' || $normalized === '0') {
            return null;
        }

        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $normalized) !== 1) {
            return null;
        }

        if (strlen($normalized) === 10 && strcmp($normalized, '2147483647') > 0) {
            return null;
        }

        return (int) $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $invoiceAmounts
     * @return array<int, array{id: int, amount: string}>
     */
    private function normalizeInvoices(array $invoiceAmounts): array
    {
        $normalized = array();
        foreach ($invoiceAmounts as $entry) {
            if (!is_array($entry) || !isset($entry['id'])) {
                continue;
            }
            $id = (int) $entry['id'];
            if ($id <= 0) {
                continue;
            }
            $amt = isset($entry['amount']) ? AmountGuard::normalizeDecimal($entry['amount']) : null;
            $normalized[] = array(
                'id' => $id,
                'amount' => $amt !== null ? $amt : '0',
            );
        }

        usort($normalized, static function (array $a, array $b): int {
            return $a['id'] <=> $b['id'];
        });

        return $normalized;
    }

    /**
     * @param array<int, array{id: int, amount: string}> $invoices
     */
    private function buildPlatformRef(string $clientId, array $invoices, string $amount, string $currency): string
    {
        if (count($invoices) > 0) {
            $ids = array();
            foreach ($invoices as $inv) {
                $ids[] = (string) $inv['id'];
            }
            $ref = 'inv_' . implode('_', $ids);
            if (strlen($ref) <= 96) {
                return $ref;
            }
            return 'inv_' . substr(hash('sha256', $ref), 0, 32);
        }

        return 'amt_' . substr(hash('sha256', trim($clientId) . '|' . $amount . '|' . $currency), 0, 24);
    }

    private function buildExternalOrderId(string $platformRef, int $renewAttempt): string
    {
        $base = 'blesta_' . $platformRef . '_r' . $renewAttempt;
        if (strlen($base) <= 120) {
            return $base;
        }

        return 'blesta_' . substr(hash('sha256', $platformRef), 0, 32) . '_r' . $renewAttempt;
    }

    private static function pendingInvoiceId(string $externalOrderId): string
    {
        return 'pending:' . $externalOrderId;
    }

    private function normalizeClientId(string $clientId): string
    {
        $trimmed = trim($clientId);
        if ($trimmed === '' || $trimmed === '0' || strpos($trimmed, '@') !== false) {
            return '';
        }

        return $trimmed;
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $this->config, $environment);
        }

        return new Client($this->config->clientConfig($environment));
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
