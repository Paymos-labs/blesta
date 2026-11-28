<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\ExternalRefundObservation;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundCopy;
use Paymos\Plugin\RefundDiscovery;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\WebhookEvent;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    private const UNMANAGED_FIELDS = array(
        'address',
        'destination_address',
        'network',
        'token',
        'parts',
        'crypto_amount',
        'recipient',
        'wallet',
        'fee',
    );

    /** @var Config */
    private $config;

    /** @var CredentialStore */
    private $credentialStore;

    /** @var SnapshotStore */
    private $snapshots;

    /** @var RefundIntentStore */
    private $store;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable|null */
    private $native;

    /** @var callable|null */
    private $authorize;

    /** @var callable */
    private $clock;

    /** @var callable|null */
    private $logger;

    /** @var string */
    private $lastNativeMessage = '';

    public function __construct(
        Config $config,
        CredentialStore $credentialStore,
        SnapshotStore $snapshots,
        RefundIntentStore $store,
        ?callable $clientFactory = null,
        ?callable $native = null,
        ?callable $authorize = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->credentialStore = $credentialStore;
        $this->snapshots = $snapshots;
        $this->store = $store;
        $this->clientFactory = $clientFactory;
        $this->native = $native;
        $this->authorize = $authorize;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
        $this->logger = $logger;
    }

    public function lastNativeMessage(): string
    {
        return $this->lastNativeMessage;
    }

    /**
     * Submit a server-calculated full or overpayment refund through RefundCoordinator.
     *
     * @param array<string, mixed> $input
     */
    public function submit(array $input): RefundIntent
    {
        $this->enforceAuthorization(true);

        foreach (self::UNMANAGED_FIELDS as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw new \RuntimeException('refund_managed_input_only');
            }
        }

        $paymentKey = isset($input['invoice_id']) && is_scalar($input['invoice_id'])
            ? trim((string) $input['invoice_id'])
            : '';
        $token = isset($input['request_token']) && is_string($input['request_token'])
            ? strtolower(trim($input['request_token']))
            : '';
        $mode = isset($input['mode']) && is_string($input['mode'])
            ? strtolower(trim($input['mode']))
            : '';

        if (preg_match('/\A[1-9][0-9]*\z/', $paymentKey) !== 1) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }
        if (!self::isValidUuidV4($token)) {
            throw new \RuntimeException('refund_durable_token_required');
        }
        if (!in_array($mode, array(RefundStates::FULL, RefundStates::OVERPAYMENT), true)) {
            throw new \RuntimeException('refund_mode_invalid');
        }

        // A refund belongs to one Paymos payment; the Blesta invoice only finds it.
        $payment = $this->resolvePayment(
            (int) $paymentKey,
            isset($input['paymos_invoice_id']) && is_string($input['paymos_invoice_id']) ? trim($input['paymos_invoice_id']) : ''
        );
        $paymosInvoiceId = $payment !== null ? (string) $payment['paymos_invoice_id'] : null;
        $paymentKey = $payment !== null ? (string) $this->paymentKeyOf($payment, (int) $paymentKey) : $paymentKey;

        return $this->store->withPaymentLock($paymentKey, function () use ($input, $paymentKey, $paymosInvoiceId, $token, $mode): RefundIntent {
            $installation = $this->store->installationId();
            $existing = $this->store->findByRequestToken($installation, $token);
            if ($existing !== null) {
                if ($existing->paymentKey() !== $paymentKey || $existing->mode() !== $mode
                    || ($paymosInvoiceId !== null && $existing->invoiceId() !== $paymosInvoiceId)) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                $this->assertRequestMatchesDraft($input, $existing->fullDraft());
                $intent = $existing;
            } else {
                $snapshot = $this->loadNativeSnapshot((int) $paymentKey, $paymosInvoiceId);
                $this->assertOriginalPaid($snapshot);
                $draft = $mode === RefundStates::FULL ? $this->buildFullDraft($snapshot) : null;
                $this->assertRequestMatchesDraft($input, $draft);

                $mapping = $snapshot['mapping'];
                $intent = new RefundIntent(array(
                    'operation_id' => RefundIntentStore::uuid(),
                    'request_token' => $token,
                    'installation_id' => $installation,
                    'platform' => 'blesta',
                    'native_payment_key' => $paymentKey,
                    'invoice_id' => (string) $mapping['paymos_invoice_id'],
                    'project_id' => (string) $mapping['project_id'],
                    'environment' => (string) $mapping['environment'],
                    'mode' => $mode,
                    'full_draft' => $draft,
                ));

                $reason = $this->preflight($intent);
                if ($reason !== null) {
                    throw new \RuntimeException(
                        $reason === NativeRefundProjectorInterface::PREFLIGHT_RETRY
                            ? 'refund_preflight_unavailable'
                            : $reason
                    );
                }

                $intent = $this->store->createIntent($intent);
            }

            $this->coordinator($intent)->submit($intent->operationId());
            $this->store->assertOwned();
            $this->reconcile($intent->operationId());

            return $this->store->getIntent($intent->operationId());
        });
    }

    /**
     * Async-safe handler for Blesta NonmerchantGateway::refund().
     * Returns ['status' => 'refunded', ...] ONLY after verified completed + applied projection.
     * Returns null (zero premature debit) while pending, processing, unknown, or failed.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function nativeRefund(array $params): ?array
    {
        $locale = isset($params['locale']) && is_string($params['locale'])
            ? Translation::normalizeLocale($params['locale'])
            : Translation::detectLocale();
        $this->lastNativeMessage = '';

        try {
            // Blesta hands over the refunded transaction: reference_id is its Paymos invoice.
            $payment = $this->findNativePaymentSnapshot($params);
            if ($payment === null && isset($params['invoice_id']) && is_numeric($params['invoice_id']) && (int) $params['invoice_id'] > 0) {
                $payment = $this->resolvePayment((int) $params['invoice_id'], '');
            }
            $invoiceId = $payment !== null ? $this->blestaInvoiceIdFromSnapshot($payment) : 0;
            if ($invoiceId <= 0) {
                // A payment that only added account credit has no Blesta invoice to refund against.
                $this->lastNativeMessage = $payment !== null && count(SnapshotStore::invoiceIds($payment)) === 0
                    ? Translation::text('Paymos.!error.refund.credit_unsupported', $locale)
                    : Translation::text('Paymos.!error.refund.failed', $locale);
                return null;
            }

            $paymosInvoiceId = (string) $payment['paymos_invoice_id'];
            $token = $this->resolveNativeRequestToken($params, $invoiceId, $paymosInvoiceId);
            $input = array(
                'invoice_id' => (string) $invoiceId,
                'paymos_invoice_id' => $paymosInvoiceId,
                'mode' => RefundStates::FULL,
                'request_token' => $token,
            );

            if (array_key_exists('amount', $params) && $params['amount'] !== null && $params['amount'] !== '') {
                $input['amount'] = (string) $params['amount'];
            }
            if (array_key_exists('currency', $params) && is_string($params['currency']) && trim($params['currency']) !== '') {
                $input['currency'] = strtoupper(trim($params['currency']));
            }

            foreach (self::UNMANAGED_FIELDS as $forbidden) {
                if (array_key_exists($forbidden, $params)) {
                    $input[$forbidden] = $params[$forbidden];
                }
            }

            $intent = $this->submit($input);

            if ($intent->cashState() === RefundStates::COMPLETED
                && $intent->projectionState() === RefundStates::PROJECTION_APPLIED
                && $intent->withdrawalId() !== null) {
                return array(
                    'status' => 'refunded',
                    'transaction_id' => $intent->withdrawalId(),
                    'reference_id' => $intent->invoiceId(),
                );
            }

            if (in_array($intent->cashState(), array(RefundStates::FAILED, RefundStates::CANCELLED, RefundStates::REVIEW), true)) {
                $reason = $intent->toArray()['review_reason'];
                $this->lastNativeMessage = Translation::text('Paymos.refunds.native_failed', $locale)
                    . ($reason ? ' (' . $reason . ')' : '');
                return null;
            }

            // Pending / processing / created / unknown: NEVER return 'refunded' prematurely!
            $this->lastNativeMessage = Translation::text('Paymos.refunds.native_in_progress', $locale);
            return null;
        } catch (\Throwable $error) {
            $reason = $error->getMessage();
            if ($reason === 'refund_partial_unsupported') {
                $this->lastNativeMessage = Translation::text('Paymos.!error.refund.partial_unsupported', $locale);
            } elseif ($reason === 'refund_reconnect_required') {
                $this->lastNativeMessage = Translation::text('Paymos.!error.refund.failed', $locale)
                    . ' ' . Translation::text('Paymos.refunds.reconnect_hint', $locale);
            } elseif ($reason === 'refund_admin_access_denied') {
                $this->lastNativeMessage = Translation::text('Paymos.!error.refund.access_denied', $locale);
            } else {
                $this->lastNativeMessage = Translation::text('Paymos.!error.refund.failed', $locale)
                    . ' (' . $this->config->sanitize($reason) . ')';
            }

            $this->log('refund.native_notice', array(
                'reason' => $this->config->sanitize($reason),
            ), 'warning');

            return null;
        }
    }

    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $snapshot = $this->loadNativeSnapshot((int) $intent->paymentKey(), $intent->invoiceId());
            $this->assertMapping($snapshot, $intent);
            $this->assertOriginalPaid($snapshot);

            if ($intent->mode() === RefundStates::FULL && $this->buildFullDraft($snapshot) !== $intent->fullDraft()) {
                return 'refund_native_draft_changed';
            }

            $refundConfig = $this->credentialStore->refundConfig($intent->environment());
            if (!in_array('invoice_refunds_v1', $refundConfig['capabilities'], true)
                || $refundConfig['refund_contract_version'] !== 1) {
                return 'refund_reconnect_required';
            }
            if ($refundConfig['refunds_enabled'] !== true) {
                return 'refund_not_enabled';
            }

            foreach ($this->store->externalForPayment($intent->paymentKey()) as $external) {
                if (($external['observation']['invoice_id'] ?? $intent->invoiceId()) !== $intent->invoiceId()) {
                    continue;
                }
                if (($external['review_reason'] ?? null) !== null
                    || (($external['observation']['mode'] ?? '') === RefundStates::FULL
                        && ($external['observation']['status'] ?? '') === 'completed')) {
                    return 'refund_external_conflict';
                }
            }

            try {
                $invoice = $this->client($intent->environment())->invoices()->get($intent->invoiceId());
            } catch (ApiException $error) {
                if ($error->statusCode() === 429 || $error->statusCode() >= 500) {
                    return NativeRefundProjectorInterface::PREFLIGHT_RETRY;
                }
                return $error->errorCode() !== '' ? $error->errorCode() : $error->getMessage();
            } catch (\RuntimeException $error) {
                return NativeRefundProjectorInterface::PREFLIGHT_RETRY;
            }

            $this->store->assertOwned();
            $this->assertInvoiceScope($invoice, $snapshot, $intent);

            $summary = $this->invoiceSummary($invoice, $intent);
            $flag = $intent->mode() === RefundStates::FULL ? 'can_refund_full' : 'can_refund_overpayment';
            $amountKey = $intent->mode() === RefundStates::FULL ? 'full_refund_amount' : 'overpayment_refund_amount';

            if (($summary[$flag] ?? false) !== true) {
                return 'refund_mode_unavailable';
            }

            if (array_key_exists('refund_summary', $invoice)) {
                if (!isset($summary[$amountKey]) || !is_string($summary[$amountKey])
                    || !preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $summary[$amountKey])
                    || AmountGuard::normalizeDecimal($summary[$amountKey]) === '0') {
                    return 'refund_mode_unavailable';
                }
            }

            return null;
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        try {
            $snapshot = $this->loadNativeSnapshot((int) $intent->paymentKey(), $intent->invoiceId());
            $this->assertMapping($snapshot, $intent);
        } catch (\Throwable $error) {
            return $this->ambiguous($intent, $error->getMessage());
        }

        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $storedProjection = $intent->toArray()['projection'];
            if (is_array($storedProjection)) {
                return NativeProjectionResult::fromStorage($storedProjection);
            }
            return new NativeProjectionResult(NativeProjectionResult::ABSENT, RefundStates::OVERPAYMENT);
        }

        $withdrawalId = $intent->withdrawalId();
        $draft = $intent->fullDraft();
        if (!is_array($draft)) {
            return $this->ambiguous($intent, 'refund_native_draft_missing');
        }

        $markers = $withdrawalId === null ? array() : $this->findNativeMarkers($intent, $draft, $withdrawalId);
        if (count($markers) > 1) {
            return $this->ambiguous($intent, 'refund_native_marker_ambiguous');
        }

        if (count($markers) === 0) {
            try {
                $this->assertOriginalPaid($snapshot);
                if ($this->buildFullDraft($snapshot) !== $draft) {
                    throw new \RuntimeException('refund_native_draft_changed');
                }
            } catch (\Throwable $error) {
                return $this->ambiguous($intent, $error->getMessage());
            }

            return new NativeProjectionResult(NativeProjectionResult::ABSENT, RefundStates::FULL);
        }

        $markerRow = $markers[0];
        $markerStatus = strtolower(trim((string) ($markerRow['status'] ?? '')));
        $markerAmount = (string) ($markerRow['amount'] ?? '');
        $markerCurrency = strtoupper(trim((string) ($markerRow['currency'] ?? '')));
        $markerClient = (string) ($markerRow['client_id'] ?? '');
        $markerId = (string) ($markerRow['id'] ?? '');

        if ($markerStatus !== 'refunded'
            || $markerId === ''
            || $markerId !== $draft['blesta_transaction_id']
            || !AmountGuard::amountsEqual($markerAmount, $draft['amount_decimal'])
            || $markerCurrency !== $draft['currency']
            || $markerClient !== $draft['client_id']) {
            return $this->ambiguous($intent, 'refund_native_marker_conflict');
        }

        return new NativeProjectionResult(
            NativeProjectionResult::EXACT_MATCH,
            RefundStates::FULL,
            $markerId,
            $draft['amount_minor'],
            $draft['currency']
        );
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $this->store->assertOwned();

        if ($refund->status() !== 'completed'
            || $refund->withdrawalId() !== $intent->withdrawalId()
            || $refund->invoiceId() !== $intent->invoiceId()
            || $refund->mode() !== $intent->mode()) {
            return $this->ambiguous($intent, 'refund_completion_required');
        }

        $inspection = $this->inspect($intent->operationId());
        if ($inspection->kind() !== NativeProjectionResult::ABSENT) {
            return $inspection;
        }

        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $asset = $refund->currency() . '/' . $refund->network();
            $note = sprintf(
                'Paymos overpayment refund completed (%s %s, withdrawal %s). Original invoice remains paid.',
                $refund->principalDecimal(),
                $asset,
                $refund->withdrawalId()
            );

            $this->store->outsideTransaction(function () use ($intent, $refund, $asset, $note): void {
                $this->callNative('record_overpayment_note', array(
                    'invoice_id' => (int) $intent->paymentKey(),
                    'paymos_invoice_id' => $intent->invoiceId(),
                    'withdrawal_id' => $refund->withdrawalId(),
                    'amount' => $refund->principalDecimal(),
                    'asset' => $asset,
                    'note' => $note,
                ));
            });

            return new NativeProjectionResult(
                NativeProjectionResult::EXACT_MATCH,
                RefundStates::OVERPAYMENT,
                'paymos_overpayment_' . $refund->withdrawalId(),
                null,
                null,
                $refund->principalDecimal(),
                $asset
            );
        }

        $draft = $intent->fullDraft();
        if (!is_array($draft)) {
            return $this->ambiguous($intent, 'refund_native_draft_missing');
        }

        $markerTag = 'paymos_refund_' . $refund->withdrawalId();
        $refundMessage = substr(
            'Paymos full refund completed [' . $markerTag . '] (' . $refund->principalDecimal() . ' ' . $refund->currency() . '/' . $refund->network() . ')',
            0,
            255
        );
        $this->store->outsideTransaction(function () use ($intent, $draft, $refund, $markerTag, $refundMessage): void {
            $this->callNative('Transactions::edit', array(
                'transaction_id' => (int) $draft['blesta_transaction_id'],
                'invoice_id' => (int) $intent->paymentKey(),
                'vars' => array(
                    'status' => 'refunded',
                    'reference_id' => $draft['original_reference_id'],
                    'transaction_id' => $draft['original_transaction_id'],
                    'message' => $refundMessage,
                    'notes' => $refundMessage,
                ),
                'marker' => $markerTag,
                'withdrawal_id' => $refund->withdrawalId(),
                'amount_decimal' => $draft['amount_decimal'],
                'amount_minor' => $draft['amount_minor'],
                'currency' => $draft['currency'],
            ));
        });

        // Re-inspect native storage to verify the Blesta transaction write committed.
        return $this->inspect($intent->operationId());
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId): string {
            $intent = $this->store->getIntent($operationId);
            $action = $this->coordinator($intent)->reconcile($operationId);
            $this->store->assertOwned();

            $updated = $this->store->getIntent($operationId);
            if ($updated->cashState() === RefundStates::COMPLETED
                && $updated->projectionState() === RefundStates::PROJECTION_APPLIED) {
                $claim = $this->store->claimEffect($operationId, 'confirmation', 1);
                if ($claim->kind() === 'claimed') {
                    $this->store->recordEffectOutcome($operationId, 'confirmation', 1, RefundStates::EFFECT_SKIPPED);
                }
            }

            return $action;
        });
    }

    public function callback(WebhookEvent $event, string $environment): void
    {
        $wire = $event->toArray()['data'] ?? array();
        if (!is_array($wire)
            || (($wire['type'] ?? '') !== 'refund' && empty($wire['invoice_id']))
            || !isset($wire['withdrawal_id'], $wire['invoice_id'], $wire['project_id'], $wire['is_test'])) {
            throw new \RuntimeException('refund_callback_scope_missing');
        }

        if ($wire['is_test'] !== ($environment === 'sandbox')) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }

        $withdrawalId = (string) $wire['withdrawal_id'];
        $intent = $this->store->findByWithdrawalId($withdrawalId);
        if ($intent === null && isset($wire['external_order_id']) && is_string($wire['external_order_id'])) {
            $intent = $this->store->findByExternalId($wire['external_order_id']);
        }

        if ($intent === null) {
            $invoiceSnapshot = $this->snapshots->findByInvoiceId((string) $wire['invoice_id']);
            if ($invoiceSnapshot === null
                || (string) ($invoiceSnapshot['environment'] ?? '') !== $environment
                || (string) ($invoiceSnapshot['project_id'] ?? '') !== (string) $wire['project_id']) {
                throw new \RuntimeException('refund_unbound_review');
            }

            $this->observeExternalRefund($wire, $environment, $invoiceSnapshot);
            return;
        }

        if ($intent->environment() !== $environment
            || (string) $wire['invoice_id'] !== $intent->invoiceId()
            || (string) $wire['project_id'] !== $intent->projectId()
            || (isset($wire['mode']) && (string) $wire['mode'] !== $intent->mode())
            || (isset($wire['refund_mode']) && (string) $wire['refund_mode'] !== $intent->mode())
            || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $withdrawalId)) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }

        $this->reconcile($intent->operationId());
    }

    /**
     * Discover refunds initiated outside Blesta (e.g., Paymos Dashboard) via RefundDiscovery
     * and verify/project completed refunds without ever calling POST /v1/refunds.
     */
    public function discoverExternal(?string $onlyEnvironment = null, ?int $onlyInvoiceId = null): int
    {
        $environments = $onlyEnvironment !== null
            ? array($onlyEnvironment)
            : array('sandbox', 'live');

        $checked = 0;
        foreach ($environments as $environment) {
            if (!$this->config->hasEnvironment($environment)) {
                continue;
            }
            $refundConfig = $this->credentialStore->refundConfig($environment);
            if ($refundConfig['project_id'] === '') {
                continue;
            }

            $cursorKey = 'refund_discovery_cursor_' . $environment . '_' . substr(hash('sha256', $refundConfig['project_id']), 0, 16);
            $cursor = $this->credentialStore->getSetting($cursorKey);
            if ($cursor === '') {
                $cursor = null;
            }

            $page = RefundDiscovery::page(
                $this->client($environment),
                $environment,
                $cursor,
                function (array $signal, string $env) use ($refundConfig, $onlyInvoiceId): bool {
                    if ($signal['project_id'] !== $refundConfig['project_id']) {
                        return false;
                    }

                    $intent = $this->store->findByWithdrawalId($signal['withdrawal_id'])
                        ?: $this->store->findByExternalId($signal['external_order_id']);
                    if ($intent !== null) {
                        if ($onlyInvoiceId !== null && (int) $intent->paymentKey() !== $onlyInvoiceId) {
                            return false;
                        }
                        $this->reconcile($intent->operationId());
                        return true;
                    }

                    $invoiceSnapshot = $this->snapshots->findByInvoiceId($signal['invoice_id']);
                    if ($invoiceSnapshot === null) {
                        return false;
                    }

                    $blestaInvoiceId = $this->blestaInvoiceIdFromSnapshot($invoiceSnapshot);
                    if ($blestaInvoiceId <= 0 || ($onlyInvoiceId !== null && $blestaInvoiceId !== $onlyInvoiceId)) {
                        return false;
                    }

                    $this->observeExternalRefund($signal, $env, $invoiceSnapshot);
                    return true;
                }
            );

            $this->credentialStore->putSetting($cursorKey, is_string($page['next_cursor']) ? $page['next_cursor'] : '');
            $checked += (int) $page['checked'];

            foreach ($page['errors'] as $errId) {
                $this->log('refund.discovery_item_error', array(
                    'environment' => $environment,
                    'withdrawal_id' => $errId,
                ), 'warning');
            }
        }

        return $checked;
    }

    /**
     * Build the admin Paymos Refunds panel state & HTML for a Blesta invoice.
     * Runs discovery and reconciles active intents so admins see fresh status.
     *
     * @param int|string $invoiceId
     * @return array<string, mixed>
     */
    public function panel($invoiceId, string $locale = 'en_us'): array
    {
        $this->enforceAuthorization(false);

        $requestedId = (int) $invoiceId;
        if ($requestedId <= 0) {
            throw new \InvalidArgumentException('refund_payment_key_invalid');
        }

        $normalizedLocale = Translation::normalizeLocale($locale);
        $sdkLocale = Translation::sdkLocale($normalizedLocale);

        // Every Paymos payment of the invoice is refunded on its own (part payments, a payment
        // of several invoices); newest first.
        $payments = $this->snapshots->findPaidPaymentsForBlestaInvoice($requestedId);

        // Best-effort discovery before computing available actions, once per payment key.
        $discovery = array();
        foreach ($payments as $payment) {
            $discovery[(string) $payment['environment'] . '|' . $this->paymentKeyOf($payment, $requestedId)] = true;
        }
        if (count($payments) === 0) {
            $latest = $this->snapshots->findLatestByBlestaInvoiceId($requestedId);
            if (is_array($latest) && isset($latest['environment']) && is_string($latest['environment'])) {
                $discovery[$latest['environment'] . '|' . $requestedId] = true;
            }
        }
        foreach (array_keys($discovery) as $scope) {
            list($environment, $key) = explode('|', $scope, 2);
            try {
                $this->discoverExternal($environment, (int) $key);
            } catch (\Throwable $e) {
                $this->log('refund.panel_discovery_failed', array(
                    'invoice_id' => (int) $key,
                    'error' => $this->config->sanitize($e->getMessage()),
                ), 'warning');
            }
        }

        $sections = array();
        foreach ($payments as $payment) {
            $sections[] = $this->paymentSection($this->paymentKeyOf($payment, $requestedId), (string) $payment['paymos_invoice_id'], $sdkLocale);
        }
        if (count($sections) === 0) {
            $sections[] = $this->paymentSection($requestedId, null, $sdkLocale);
        }

        $html = '';
        foreach ($sections as $section) {
            $heading = count($sections) > 1 && $section['paymos_invoice_id'] !== null
                ? strtr(Translation::text('Paymos.refunds.payment_label', $normalizedLocale), array(
                    '{paymos_invoice_id}' => (string) $section['paymos_invoice_id'],
                    '{amount}' => (string) $section['amount'],
                    '{currency}' => (string) $section['currency'],
                ))
                : null;
            $html .= $this->renderPanelHtml(
                $section['invoice_id'],
                $normalizedLocale,
                $section['intents'],
                $section['external_refunds'],
                $section['can_refund_full'],
                $section['can_refund_overpayment'],
                $section['tokens'],
                $section['paymos_invoice_id'],
                $heading
            );
        }

        $primary = $sections[0];
        return array(
            'invoice_id' => $primary['invoice_id'],
            'paymos_invoice_id' => $primary['paymos_invoice_id'],
            'environment' => $primary['environment'],
            'can_refund_full' => $primary['can_refund_full'],
            'can_refund_overpayment' => $primary['can_refund_overpayment'],
            'tokens' => $primary['tokens'],
            'intents' => $primary['intents'],
            'external_refunds' => $primary['external_refunds'],
            'payments' => $sections,
            'copy' => RefundCopy::labels($sdkLocale),
            'html' => $html,
        );
    }

    /**
     * Refund state of one Paymos payment (or, with $paymosInvoiceId null, of the key as a whole).
     *
     * @return array<string, mixed>
     */
    private function paymentSection(int $id, ?string $paymosInvoiceId, string $sdkLocale): array
    {
        $paymentKey = (string) $id;

        foreach ($this->intentsOf($paymentKey, $paymosInvoiceId) as $intent) {
            if (!in_array($intent->cashState(), array(RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED), true)
                || ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() !== RefundStates::PROJECTION_APPLIED)) {
                try {
                    $this->reconcile($intent->operationId());
                } catch (\Throwable $e) {
                    $this->log('refund.panel_reconcile_failed', array(
                        'operation_id' => $intent->operationId(),
                        'error' => $this->config->sanitize($e->getMessage()),
                    ), 'warning');
                }
            }
        }

        $tokens = array(
            RefundStates::FULL => RefundIntentStore::uuid(),
            RefundStates::OVERPAYMENT => RefundIntentStore::uuid(),
        );
        $hasBlocking = false;
        $hasCompletedFull = false;
        $hasCompletedOverpayment = false;
        $intentItems = array();

        foreach ($this->intentsOf($paymentKey, $paymosInvoiceId) as $intent) {
            if ($this->store->blocksNewIntent($intent)) {
                $hasBlocking = true;
                $tokens[$intent->mode()] = $intent->toArray()['request_token'];
            }
            if ($intent->mode() === RefundStates::FULL && $intent->cashState() === RefundStates::COMPLETED) {
                $hasCompletedFull = true;
            }
            if ($intent->mode() === RefundStates::OVERPAYMENT && $intent->cashState() === RefundStates::COMPLETED) {
                $hasCompletedOverpayment = true;
            }
            $obs = $intent->observation();
            $intentItems[] = array(
                'operation_id' => $intent->operationId(),
                'mode' => $intent->mode(),
                'cash_state' => $intent->cashState(),
                'projection_state' => $intent->projectionState(),
                'withdrawal_id' => $intent->withdrawalId(),
                'review_reason' => $intent->toArray()['review_reason'],
                'amount' => $obs !== null ? $obs->principalDecimal() : null,
                'asset' => $obs !== null ? $obs->currency() . '/' . $obs->network() : null,
                'status_copy' => RefundCopy::state(
                    $intent->cashState(),
                    $intent->mode(),
                    $intent->projectionState(),
                    $intent->toArray()['review_reason'],
                    $sdkLocale
                ),
            );
        }

        $externalItems = array();
        foreach ($this->store->externalForPayment($paymentKey) as $ext) {
            $obs = isset($ext['observation']) && is_array($ext['observation']) ? $ext['observation'] : array();
            if ($paymosInvoiceId !== null && (string) ($obs['invoice_id'] ?? $paymosInvoiceId) !== $paymosInvoiceId) {
                continue;
            }
            $extMode = (string) ($obs['mode'] ?? '');
            $extStatus = (string) ($obs['status'] ?? '');
            if ($extMode === RefundStates::FULL && $extStatus === 'completed') {
                $hasCompletedFull = true;
            }
            if ($extMode === RefundStates::OVERPAYMENT && $extStatus === 'completed') {
                $hasCompletedOverpayment = true;
            }
            if (($ext['review_reason'] ?? null) !== null) {
                $hasBlocking = true;
            }
            $externalItems[] = array(
                'withdrawal_id' => (string) ($obs['withdrawal_id'] ?? ''),
                'mode' => $extMode,
                'status' => $extStatus,
                'amount' => (string) ($obs['amount'] ?? ''),
                'asset' => (string) (($obs['currency'] ?? '') . '/' . ($obs['network'] ?? '')),
                'projected' => ($ext['note_id'] ?? null) !== null,
                'review_reason' => $ext['review_reason'] ?? null,
                'label' => RefundCopy::text('external_label', $sdkLocale),
            );
        }

        $canRefundFull = false;
        $canRefundOverpayment = false;
        $environment = null;
        $amount = null;
        $currency = null;

        try {
            $nativeSnap = $this->loadNativeSnapshot($id, $paymosInvoiceId);
            $this->assertOriginalPaid($nativeSnap);
            $mapping = $nativeSnap['mapping'];
            $environment = (string) $mapping['environment'];
            $paymosInvoiceId = (string) $mapping['paymos_invoice_id'];
            $amount = (string) $mapping['amount'];
            $currency = (string) $mapping['currency'];
            $refundConfig = $this->credentialStore->refundConfig($environment);
            $capable = in_array('invoice_refunds_v1', $refundConfig['capabilities'], true)
                && $refundConfig['refund_contract_version'] === 1
                && $refundConfig['refunds_enabled'] === true
                && $refundConfig['project_id'] === (string) $mapping['project_id'];

            if ($capable && !$hasBlocking && !$hasCompletedFull) {
                $canRefundFull = true;
                if (!$hasCompletedOverpayment && (($mapping['status'] ?? '') === 'paid_over')) {
                    $canRefundOverpayment = true;
                }
            }
        } catch (\Throwable $e) {
            $canRefundFull = false;
            $canRefundOverpayment = false;
        }

        return array(
            'invoice_id' => $id,
            'paymos_invoice_id' => $paymosInvoiceId,
            'environment' => $environment,
            'amount' => $amount,
            'currency' => $currency,
            'can_refund_full' => $canRefundFull,
            'can_refund_overpayment' => $canRefundOverpayment,
            'tokens' => $tokens,
            'intents' => $intentItems,
            'external_refunds' => $externalItems,
        );
    }

    /**
     * Cron sweep: reconcile due refund intents and discover Dashboard-originated refunds.
     */
    public function run(?int $now = null): void
    {
        $ts = $now ?? $this->now();
        $page = $this->store->listDue(new \DateTimeImmutable('@' . $ts), 100, null);

        foreach ($page->items() as $intent) {
            try {
                if ($intent->cashState() === RefundStates::NOT_SUBMITTED
                    && $intent->projectionState() !== RefundStates::NATIVE_REVIEW) {
                    $this->coordinator($intent)->submit($intent->operationId());
                }
                $this->reconcile($intent->operationId());
            } catch (\Throwable $error) {
                $this->log('refund.cron_reconcile_failed', array(
                    'operation_id' => $intent->operationId(),
                    'reason' => $this->config->sanitize($error->getMessage()),
                ), 'warning');
            } finally {
                try {
                    $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent, $ts): void {
                        $this->store->defer($intent->operationId(), $ts);
                    });
                } catch (\Throwable $deferError) {
                    $this->log('refund.cron_defer_failed', array(
                        'operation_id' => $intent->operationId(),
                        'reason' => $this->config->sanitize($deferError->getMessage()),
                    ), 'warning');
                }
            }
        }

        try {
            $this->discoverExternal();
        } catch (\Throwable $error) {
            $this->log('refund.cron_discovery_failed', array(
                'reason' => $this->config->sanitize($error->getMessage()),
            ), 'warning');
        }
    }

    /**
     * Observe, verify via signed GETs, and project a Dashboard-initiated refund
     * without ever calling POST /v1/refunds.
     *
     * @param array<string, mixed> $signal
     * @param array<string, mixed> $invoiceSnapshot
     */
    private function observeExternalRefund(array $signal, string $environment, array $invoiceSnapshot): void
    {
        $blestaInvoiceId = $this->blestaInvoiceIdFromSnapshot($invoiceSnapshot);
        if ($blestaInvoiceId <= 0) {
            throw new \RuntimeException('refund_external_mapping_missing');
        }
        $paymentKey = (string) $blestaInvoiceId;

        $this->store->withPaymentLock($paymentKey, function () use ($signal, $environment, $invoiceSnapshot, $blestaInvoiceId, $paymentKey): void {
            $withdrawalId = (string) $signal['withdrawal_id'];
            $client = $this->client($environment);
            $wire = $client->refunds()->get($withdrawalId);
            $invoice = $client->invoices()->get((string) $signal['invoice_id']);
            $this->store->assertOwned();

            $verified = VerifiedRefund::fromApi($wire, $invoice, new \DateTimeImmutable('@' . $this->now()));

            if ($verified->environment() !== $environment
                || $verified->invoiceId() !== (string) ($invoiceSnapshot['paymos_invoice_id'] ?? '')
                || $verified->projectId() !== (string) ($invoiceSnapshot['project_id'] ?? '')) {
                throw new \RuntimeException('refund_external_scope_conflict');
            }

            $old = $this->store->findExternalByWithdrawalId($withdrawalId);
            $row = ExternalRefundObservation::fold($old, $verified, $paymentKey);

            // Project verified completed external refunds once idempotently.
            if ($verified->status() === 'completed' && ($row['note_id'] ?? null) === null) {
                if ($verified->mode() === RefundStates::OVERPAYMENT) {
                    $asset = $verified->currency() . '/' . $verified->network();
                    $note = sprintf(
                        'Paymos Dashboard overpayment refund completed (%s %s, withdrawal %s). Original invoice remains paid.',
                        $verified->principalDecimal(),
                        $asset,
                        $verified->withdrawalId()
                    );
                    $this->callNative('record_overpayment_note', array(
                        'invoice_id' => $blestaInvoiceId,
                        'paymos_invoice_id' => $verified->invoiceId(),
                        'withdrawal_id' => $verified->withdrawalId(),
                        'amount' => $verified->principalDecimal(),
                        'asset' => $asset,
                        'note' => $note,
                    ));
                    $row['note_id'] = 'paymos_overpayment_' . $verified->withdrawalId();
                    $row['review_reason'] = null;
                } elseif ($verified->mode() === RefundStates::FULL) {
                    $nativeSnap = $this->loadNativeSnapshot($blestaInvoiceId, $verified->invoiceId());
                    $markerTag = 'paymos_refund_' . $verified->withdrawalId();
                    $existingMarkers = $this->findNativeMarkersByWithdrawal($nativeSnap, $verified->withdrawalId());
                    if (count($existingMarkers) > 1) {
                        $row['note_id'] = null;
                        $row['review_reason'] = 'refund_native_marker_ambiguous';
                    } elseif (count($existingMarkers) === 1) {
                        $existing = $existingMarkers[0];
                        $existingStatus = strtolower(trim((string) ($existing['status'] ?? '')));
                        $existingId = (string) ($existing['id'] ?? '');
                        $expectedCurrency = strtoupper(trim((string) ($nativeSnap['mapping']['currency'] ?? '')));
                        $expectedAmount = (string) ($nativeSnap['mapping']['amount'] ?? '');
                        if ($existingStatus === 'refunded'
                            && $existingId !== ''
                            && AmountGuard::amountsEqual((string) ($existing['amount'] ?? ''), $expectedAmount)
                            && strtoupper(trim((string) ($existing['currency'] ?? ''))) === $expectedCurrency) {
                            $row['note_id'] = $existingId;
                            $row['review_reason'] = null;
                        } else {
                            $row['note_id'] = null;
                            $row['review_reason'] = 'refund_native_marker_conflict';
                        }
                    } else {
                        try {
                            $this->assertOriginalPaid($nativeSnap);
                            $draft = $this->buildFullDraft($nativeSnap);
                        } catch (\Throwable $guardError) {
                            $row['note_id'] = null;
                            $row['review_reason'] = $guardError->getMessage() !== ''
                                ? $guardError->getMessage()
                                : 'refund_native_state_conflict';
                            $draft = null;
                        }

                        if ($draft !== null) {
                            $extRefundMessage = substr(
                                'Paymos Dashboard full refund completed [' . $markerTag . '] (' . $verified->principalDecimal() . ' ' . $verified->currency() . '/' . $verified->network() . ')',
                                0,
                                255
                            );
                            $editResult = $this->callNative('Transactions::edit', array(
                                'transaction_id' => (int) $draft['blesta_transaction_id'],
                                'invoice_id' => $blestaInvoiceId,
                                'vars' => array(
                                    'status' => 'refunded',
                                    'reference_id' => $draft['original_reference_id'],
                                    'transaction_id' => $draft['original_transaction_id'],
                                    'message' => $extRefundMessage,
                                    'notes' => $extRefundMessage,
                                ),
                                'marker' => $markerTag,
                                'withdrawal_id' => $verified->withdrawalId(),
                                'amount_decimal' => $draft['amount_decimal'],
                                'amount_minor' => $draft['amount_minor'],
                                'currency' => $draft['currency'],
                            ));

                            $editFailed = ($editResult['result'] ?? 'success') !== 'success'
                                || (($editResult['ok'] ?? true) === false)
                                || !empty($editResult['errors']);

                            $postSnap = $this->loadNativeSnapshot($blestaInvoiceId, $verified->invoiceId());
                            $postMarkers = $this->findNativeMarkersByWithdrawal($postSnap, $verified->withdrawalId());
                            if (!$editFailed
                                && count($postMarkers) === 1
                                && strtolower(trim((string) ($postMarkers[0]['status'] ?? ''))) === 'refunded'
                                && (string) ($postMarkers[0]['id'] ?? '') === $draft['blesta_transaction_id']) {
                                $row['note_id'] = $draft['blesta_transaction_id'];
                                $row['review_reason'] = null;
                            } else {
                                $row['note_id'] = null;
                                $row['review_reason'] = 'refund_native_projection_failed';
                            }
                        }
                    }
                }
            }

            $this->store->saveExternalObservation($withdrawalId, $paymentKey, $verified->invoiceId(), $row);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $draft
     */
    private function assertRequestMatchesDraft(array $input, ?array $draft): void
    {
        if ($draft === null) {
            if (array_key_exists('amount', $input) || array_key_exists('currency', $input)) {
                throw new \RuntimeException('refund_cash_only_input');
            }
            return;
        }

        if (array_key_exists('amount', $input)) {
            $norm = AmountGuard::normalizeDecimal($input['amount']);
            if ($norm === null || !AmountGuard::amountsEqual($norm, (string) $draft['amount_decimal'])) {
                throw new \RuntimeException('refund_partial_unsupported');
            }
        }

        if (array_key_exists('currency', $input)) {
            if (!is_string($input['currency']) || strtoupper(trim($input['currency'])) !== $draft['currency']) {
                throw new \RuntimeException('refund_partial_unsupported');
            }
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function assertMapping(array $snapshot, RefundIntent $intent): void
    {
        $mapping = isset($snapshot['mapping']) && is_array($snapshot['mapping']) ? $snapshot['mapping'] : array();
        $refundConfig = $this->credentialStore->refundConfig($intent->environment());

        if ((string) ($snapshot['invoice']['id'] ?? '') !== $intent->paymentKey()
            || (string) ($mapping['paymos_invoice_id'] ?? '') !== $intent->invoiceId()
            || (string) ($mapping['project_id'] ?? '') !== $intent->projectId()
            || $refundConfig['project_id'] !== $intent->projectId()
            || (string) ($mapping['environment'] ?? '') !== $intent->environment()
            || trim((string) ($mapping['external_order_id'] ?? '')) === '') {
            throw new \RuntimeException('refund_payment_mapping_conflict');
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function assertOriginalPaid(array $snapshot): void
    {
        $invoice = isset($snapshot['invoice']) && is_array($snapshot['invoice']) ? $snapshot['invoice'] : array();
        $mapping = isset($snapshot['mapping']) && is_array($snapshot['mapping']) ? $snapshot['mapping'] : array();
        $tx = isset($snapshot['transaction']) && is_array($snapshot['transaction']) ? $snapshot['transaction'] : array();

        $txStatus = strtolower(trim((string) ($tx['status'] ?? '')));
        $mappingStatus = strtolower(trim((string) ($mapping['status'] ?? '')));

        if ($txStatus !== 'approved'
            || !in_array($mappingStatus, array('paid', 'paid_over'), true)
            || empty($invoice['id'])
            || empty($invoice['client_id'])
            || empty($tx['id'])
            || !isset($tx['amount'], $tx['currency'], $mapping['amount'], $mapping['currency'])
            || !AmountGuard::amountsEqual((string) $tx['amount'], (string) $mapping['amount'])
            || strtoupper(trim((string) $tx['currency'])) !== strtoupper(trim((string) $mapping['currency']))) {
            throw new \RuntimeException('refund_original_full_paid_required');
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buildFullDraft(array $snapshot): array
    {
        $invoice = $snapshot['invoice'];
        $tx = $snapshot['transaction'];
        $mapping = $snapshot['mapping'];

        $total = AmountGuard::normalizeDecimal((string) $tx['amount']);
        if ($total === null || $total === '0' || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $total) !== 1) {
            throw new \RuntimeException('refund_native_precision_invalid');
        }

        $parts = explode('.', $total, 2);
        $minor = ltrim($parts[0] . str_pad($parts[1] ?? '', 2, '0'), '0');
        if (preg_match('/\A[1-9][0-9]*\z/', $minor) !== 1) {
            throw new \RuntimeException('refund_native_precision_invalid');
        }

        return array(
            'amount_decimal' => $total,
            'amount_minor' => $minor,
            'currency' => strtoupper(trim((string) $tx['currency'])),
            'client_id' => (string) $invoice['client_id'],
            'blesta_transaction_id' => (string) $tx['id'],
            'original_transaction_id' => (string) ($tx['transaction_id'] ?? $mapping['transaction_id'] ?? ''),
            'original_reference_id' => (string) ($tx['reference_id'] ?? $mapping['paymos_invoice_id'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $snapshot
     */
    private function assertInvoiceScope(array $invoice, array $snapshot, RefundIntent $intent): void
    {
        $mapping = $snapshot['mapping'];
        $order = isset($invoice['order']) && is_array($invoice['order']) ? $invoice['order'] : array();

        if (($invoice['invoice_id'] ?? '') !== $intent->invoiceId()
            || ($invoice['project_id'] ?? '') !== $intent->projectId()
            || ($invoice['is_test'] ?? null) !== ($intent->environment() === 'sandbox')
            || !empty($invoice['paid_late_at'])
            || ($order['external_id'] ?? '') !== (string) $mapping['external_order_id']
            || !AmountGuard::amountsEqual((string) ($order['amount'] ?? ''), (string) $mapping['amount'])
            || strtoupper(trim((string) ($order['currency'] ?? ''))) !== strtoupper(trim((string) $mapping['currency']))) {
            throw new \RuntimeException('refund_invoice_scope_conflict');
        }
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    private function invoiceSummary(array $invoice, RefundIntent $intent): array
    {
        if (array_key_exists('refund_summary', $invoice)) {
            return is_array($invoice['refund_summary']) ? $invoice['refund_summary'] : array();
        }

        $status = isset($invoice['status']) && is_string($invoice['status']) ? strtolower(trim($invoice['status'])) : '';
        $completedOverpayment = false;
        foreach ($this->store->forPayment($intent->paymentKey()) as $prior) {
            if ($prior->invoiceId() === $intent->invoiceId()
                && $prior->mode() === RefundStates::OVERPAYMENT
                && $prior->cashState() === RefundStates::COMPLETED) {
                $completedOverpayment = true;
                break;
            }
        }
        foreach ($this->store->externalForPayment($intent->paymentKey()) as $ext) {
            $obs = isset($ext['observation']) && is_array($ext['observation']) ? $ext['observation'] : array();
            if (($obs['invoice_id'] ?? '') === $intent->invoiceId()
                && ($obs['mode'] ?? '') === RefundStates::OVERPAYMENT
                && ($obs['status'] ?? '') === 'completed') {
                $completedOverpayment = true;
                break;
            }
        }

        return array(
            'can_refund_full' => in_array($status, array('paid', 'paid_over'), true),
            'can_refund_overpayment' => $status === 'paid_over' && !$completedOverpayment,
            'full_refund_amount' => null,
            'overpayment_refund_amount' => null,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function findNativePaymentSnapshot(array $params): ?array
    {
        $snapshot = null;
        if (isset($params['reference_id']) && is_scalar($params['reference_id']) && trim((string) $params['reference_id']) !== '') {
            $snapshot = $this->snapshots->findByInvoiceId(trim((string) $params['reference_id']));
        }
        if ($snapshot === null && isset($params['transaction_id']) && is_scalar($params['transaction_id']) && trim((string) $params['transaction_id']) !== '') {
            $snapshot = $this->snapshots->findByTransactionId(trim((string) $params['transaction_id']));
        }

        return $snapshot;
    }

    /**
     * The Paymos payment a refund of Blesta invoice $blestaInvoiceId is for. One invoice may
     * hold several payments (part payments, a payment of several invoices): the caller names
     * the payment, or the invoice must have exactly one. Null when the invoice has none.
     *
     * @return array<string, mixed>|null
     */
    private function resolvePayment(int $blestaInvoiceId, string $paymosInvoiceId): ?array
    {
        if ($paymosInvoiceId !== '') {
            $payment = $this->snapshots->findByInvoiceId($paymosInvoiceId);
            if (!is_array($payment)
                || !in_array(strtolower((string) ($payment['final_status'] ?? $payment['status'] ?? '')), array('paid', 'paid_over'), true)
                || !in_array($blestaInvoiceId, SnapshotStore::invoiceIds($payment), true)) {
                throw new \RuntimeException('refund_payment_mapping_conflict');
            }
            return $payment;
        }

        $payments = $this->snapshots->findPaidPaymentsForBlestaInvoice($blestaInvoiceId);
        if (count($payments) > 1) {
            throw new \RuntimeException('refund_payment_selection_required');
        }

        return count($payments) === 1 ? $payments[0] : null;
    }

    /**
     * Refund intents of a payment are grouped (and locked) under the first Blesta invoice the
     * payment closed; each intent still names its own Paymos invoice.
     *
     * @param array<string, mixed> $payment
     */
    private function paymentKeyOf(array $payment, int $fallback): int
    {
        $ids = SnapshotStore::invoiceIds($payment);
        return count($ids) > 0 ? $ids[0] : $fallback;
    }

    /**
     * Intents of one Paymos payment (all intents of the key when the payment is unknown).
     *
     * @return array<int, RefundIntent>
     */
    private function intentsOf(string $paymentKey, ?string $paymosInvoiceId): array
    {
        $result = array();
        foreach ($this->store->forPayment($paymentKey) as $intent) {
            if ($paymosInvoiceId === null || $intent->invoiceId() === $paymosInvoiceId) {
                $result[] = $intent;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveNativeRequestToken(array $params, int $invoiceId, string $paymosInvoiceId): string
    {
        if (isset($params['request_token']) && is_string($params['request_token']) && self::isValidUuidV4($params['request_token'])) {
            return strtolower(trim($params['request_token']));
        }
        if (isset($_POST['paymos_refund_token']) && is_string($_POST['paymos_refund_token']) && self::isValidUuidV4($_POST['paymos_refund_token'])) {
            return strtolower(trim($_POST['paymos_refund_token']));
        }

        $endedUnrefunded = 0;
        foreach ($this->intentsOf((string) $invoiceId, $paymosInvoiceId) as $intent) {
            if ($intent->mode() !== RefundStates::FULL) {
                continue;
            }
            if ($this->store->blocksNewIntent($intent)) {
                return (string) $intent->toArray()['request_token'];
            }
            if (in_array($intent->cashState(), array(RefundStates::FAILED, RefundStates::CANCELLED), true)) {
                $endedUnrefunded++;
            }
        }

        // Deterministic UUIDv4-formatted durable token for native gateway button retries: a repeated
        // press reuses it, while each failed or cancelled refund moves the next press to a new token.
        $seed = $this->store->installationId() . '|blesta|native_full|' . $invoiceId . '|' . $paymosInvoiceId;
        if ($endedUnrefunded > 0) {
            $seed .= '|' . $endedUnrefunded;
        }
        $hash = hash('sha256', $seed);
        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 1)) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function blestaInvoiceIdFromSnapshot(array $snapshot): int
    {
        if (isset($snapshot['blesta_invoice_id']) && is_numeric($snapshot['blesta_invoice_id']) && (int) $snapshot['blesta_invoice_id'] > 0) {
            return (int) $snapshot['blesta_invoice_id'];
        }

        $meta = SnapshotStore::decodeMeta(isset($snapshot['payload_meta']) ? (string) $snapshot['payload_meta'] : null);
        if (isset($meta['invoices'][0]['id']) && is_numeric($meta['invoices'][0]['id'])) {
            return (int) $meta['invoices'][0]['id'];
        }

        if (isset($snapshot['platform_ref']) && is_string($snapshot['platform_ref'])
            && preg_match('/\Ainv_([1-9][0-9]*)\z/', $snapshot['platform_ref'], $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Blesta state of one Paymos payment: the invoice the refund is keyed by, the payment's
     * snapshot (mapping) and its Blesta transaction. Without $paymosInvoiceId the latest
     * payment of the invoice is used (one-payment invoices, legacy callers).
     *
     * @return array<string, mixed>
     */
    private function loadNativeSnapshot(int $invoiceId, ?string $paymosInvoiceId = null): array
    {
        $input = array('invoice_id' => $invoiceId);
        if ($paymosInvoiceId !== null && $paymosInvoiceId !== '') {
            $input['paymos_invoice_id'] = $paymosInvoiceId;
        }

        return $this->callNative('snapshot', $input);
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<int, array<string, mixed>>
     */
    private function findNativeMarkers(RefundIntent $intent, array $draft, string $withdrawalId): array
    {
        return $this->callNative('marker', array(
            'invoice_id' => (int) $intent->paymentKey(),
            'transaction_id' => (int) $draft['blesta_transaction_id'],
            'withdrawal_id' => $withdrawalId,
            'marker' => 'paymos_refund_' . $withdrawalId,
        ));
    }

    /**
     * @param array<string, mixed> $nativeSnap
     * @return array<int, array<string, mixed>>
     */
    private function findNativeMarkersByWithdrawal(array $nativeSnap, string $withdrawalId): array
    {
        $txId = isset($nativeSnap['transaction']['id']) && is_numeric($nativeSnap['transaction']['id'])
            ? (int) $nativeSnap['transaction']['id']
            : 0;
        $invoiceId = isset($nativeSnap['invoice']['id']) && is_numeric($nativeSnap['invoice']['id'])
            ? (int) $nativeSnap['invoice']['id']
            : 0;

        return $this->callNative('marker', array(
            'invoice_id' => $invoiceId,
            'transaction_id' => $txId,
            'withdrawal_id' => $withdrawalId,
            'marker' => 'paymos_refund_' . $withdrawalId,
        ));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function callNative(string $command, array $input): array
    {
        if ($this->native !== null) {
            $result = call_user_func($this->native, $command, $input);
            return is_array($result) ? $result : array();
        }

        if ($command === 'snapshot') {
            $invoiceId = (int) $input['invoice_id'];
            $paymosInvoiceId = isset($input['paymos_invoice_id']) ? trim((string) $input['paymos_invoice_id']) : '';
            if ($paymosInvoiceId !== '') {
                $mapping = $this->snapshots->findByInvoiceId($paymosInvoiceId);
                if ($mapping !== null && !in_array($invoiceId, SnapshotStore::invoiceIds($mapping), true)) {
                    $mapping = null;
                }
            } else {
                $mapping = $this->snapshots->findPaidByBlestaInvoiceId($invoiceId)
                    ?: $this->snapshots->findLatestByBlestaInvoiceId($invoiceId);
            }
            if ($mapping === null) {
                throw new \RuntimeException('refund_payment_mapping_missing');
            }

            if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                throw new \RuntimeException('refund_native_api_unavailable');
            }

            $host = new \stdClass();
            \Loader::loadModels($host, array('Invoices', 'Transactions'));
            if (!isset($host->Invoices, $host->Transactions)) {
                throw new \RuntimeException('refund_native_api_unavailable');
            }

            $invObj = $host->Invoices->get($invoiceId);
            if (!is_object($invObj)) {
                throw new \RuntimeException('refund_payment_mapping_missing');
            }

            $txObj = null;
            $mappingTxId = trim((string) ($mapping['transaction_id'] ?? ''));
            $paymosInvoiceId = trim((string) ($mapping['paymos_invoice_id'] ?? ''));

            if (method_exists($host->Transactions, 'getByTransactionId')) {
                foreach (array_unique(array_filter(array($mappingTxId, $paymosInvoiceId), static fn (string $v): bool => $v !== '')) as $candidateTxId) {
                    $found = $host->Transactions->getByTransactionId($candidateTxId, (int) $invObj->client_id);
                    if (is_object($found)) {
                        $txObj = $found;
                        break;
                    }
                }
            }
            if (!is_object($txObj) && method_exists($host->Transactions, 'getByReference')) {
                $txObj = $host->Transactions->getByReference($paymosInvoiceId, $mappingTxId);
            }
            if (!is_object($txObj) && method_exists($host->Transactions, 'getList')) {
                // Blesta Transactions::getList($client_id = null, $status = 'approved', ...) defaults
                // to $status = 'approved', which hides transactions already marked 'refunded' right after
                // Transactions::edit(). Pass null so all statuses are searched (needed by post-apply inspect()).
                $txList = $host->Transactions->getList((int) $invObj->client_id, null);
                if (is_array($txList)) {
                    foreach ($txList as $candidate) {
                        if (!is_object($candidate)) {
                            continue;
                        }
                        $candRef = trim((string) ($candidate->reference_id ?? ''));
                        $candTx = trim((string) ($candidate->transaction_id ?? ''));
                        if (($paymosInvoiceId !== '' && ($candRef === $paymosInvoiceId || $candTx === $paymosInvoiceId))
                            || ($mappingTxId !== '' && $candTx === $mappingTxId)) {
                            $txObj = $candidate;
                            break;
                        }
                    }
                }
            }

            if (!is_object($txObj)) {
                throw new \RuntimeException('refund_original_payment_ambiguous');
            }

            return array(
                'invoice' => array(
                    'id' => (int) $invObj->id,
                    'client_id' => (string) $invObj->client_id,
                    'status' => (string) ($invObj->status ?? ''),
                    'total' => (string) ($invObj->total ?? $mapping['amount']),
                    'currency' => strtoupper(trim((string) ($invObj->currency ?? $mapping['currency']))),
                ),
                'transaction' => array(
                    'id' => (string) $txObj->id,
                    'client_id' => (string) ($txObj->client_id ?? $invObj->client_id),
                    'transaction_id' => (string) ($txObj->transaction_id ?? $mapping['transaction_id'] ?? ''),
                    'reference_id' => (string) ($txObj->reference_id ?? $mapping['paymos_invoice_id']),
                    'amount' => (string) ($txObj->amount ?? $mapping['amount']),
                    'currency' => strtoupper(trim((string) ($txObj->currency ?? $mapping['currency']))),
                    'status' => (string) ($txObj->status ?? 'approved'),
                    'message' => trim((string) ($txObj->message ?? '')) !== '' ? (string) $txObj->message : (string) ($txObj->notes ?? ''),
                    'notes' => trim((string) ($txObj->message ?? '')) !== '' ? (string) $txObj->message : (string) ($txObj->notes ?? ''),
                ),
                'mapping' => $mapping,
            );
        }

        if ($command === 'marker') {
            if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                return array();
            }
            $host = new \stdClass();
            \Loader::loadModels($host, array('Transactions'));
            if (!isset($host->Transactions) || !method_exists($host->Transactions, 'get')) {
                return array();
            }
            $tx = $host->Transactions->get((int) $input['transaction_id']);
            if (!is_object($tx)) {
                return array();
            }
            $notes = trim((string) ($tx->message ?? '')) !== ''
                ? (string) $tx->message
                : (string) ($tx->notes ?? '');
            $marker = (string) ($input['marker'] ?? '');
            if (strtolower((string) ($tx->status ?? '')) === 'refunded'
                && ($marker === '' || strpos($notes, $marker) !== false)) {
                return array(array(
                    'id' => (string) $tx->id,
                    'client_id' => (string) ($tx->client_id ?? ''),
                    'status' => (string) ($tx->status ?? ''),
                    'amount' => (string) ($tx->amount ?? ''),
                    'currency' => strtoupper(trim((string) ($tx->currency ?? ''))),
                    'message' => $notes,
                    'notes' => $notes,
                ));
            }
            return array();
        }

        if ($command === 'Transactions::edit') {
            if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                throw new \RuntimeException('refund_native_api_unavailable');
            }
            $host = new \stdClass();
            \Loader::loadModels($host, array('Transactions'));
            if (!isset($host->Transactions) || !method_exists($host->Transactions, 'edit')) {
                throw new \RuntimeException('refund_native_api_unavailable');
            }
            $host->Transactions->edit((int) $input['transaction_id'], (array) $input['vars']);
            if (method_exists($host->Transactions, 'errors')) {
                $errors = $host->Transactions->errors();
                if (!empty($errors)) {
                    return array('result' => 'error', 'errors' => $errors);
                }
            }
            return array('result' => 'success');
        }

        if ($command === 'record_overpayment_note') {
            $snapshot = $this->snapshots->findByInvoiceId((string) ($input['paymos_invoice_id'] ?? ''));
            if (is_array($snapshot)) {
                $meta = SnapshotStore::decodeMeta(isset($snapshot['payload_meta']) ? (string) $snapshot['payload_meta'] : null);
                $notes = isset($meta['overpayment_notes']) && is_array($meta['overpayment_notes'])
                    ? $meta['overpayment_notes']
                    : array();
                $notes[(string) $input['withdrawal_id']] = (string) $input['note'];
                $this->snapshots->updateMetaFlag((string) $snapshot['external_order_id'], 'overpayment_notes', $notes);
            }
            $this->log('refund.overpayment_completed', array(
                'invoice_id' => (int) ($input['invoice_id'] ?? 0),
                'withdrawal_id' => (string) ($input['withdrawal_id'] ?? ''),
                'amount' => (string) ($input['amount'] ?? ''),
                'asset' => (string) ($input['asset'] ?? ''),
            ), 'info');
            return array('result' => 'success');
        }

        throw new \RuntimeException('refund_native_command_unknown');
    }

    /**
     * @param array<int, array<string, mixed>> $intents
     * @param array<int, array<string, mixed>> $externalRefunds
     * @param array<string, string> $tokens
     */
    private function renderPanelHtml(
        int $invoiceId,
        string $locale,
        array $intents,
        array $externalRefunds,
        bool $canRefundFull,
        bool $canRefundOverpayment,
        array $tokens,
        ?string $paymosInvoiceId = null,
        ?string $heading = null
    ): string {
        $e = static function (string $value): string {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };

        $html = '<div class="paymos-refunds-panel" data-invoice-id="' . $invoiceId . '"'
            . ($paymosInvoiceId !== null ? ' data-paymos-invoice-id="' . $e($paymosInvoiceId) . '"' : '') . '>';
        $html .= '<h4>' . $e(Translation::text('Paymos.refunds.title', $locale)) . '</h4>';
        if ($heading !== null) {
            $html .= '<p class="paymos-refund-payment">' . $e($heading) . '</p>';
        }

        if (count($intents) === 0 && count($externalRefunds) === 0) {
            $html .= '<p class="paymos-refunds-empty">' . $e(Translation::text('Paymos.refunds.empty', $locale)) . '</p>';
        }

        foreach ($intents as $item) {
            $html .= '<div class="paymos-refund-item">';
            $html .= '<p>' . $e(Translation::text('Paymos.refunds.operation', $locale)) . ': <code>' . $e((string) $item['operation_id']) . '</code></p>';
            $html .= '<p>' . $e(Translation::text('Paymos.refunds.type', $locale)) . ': <code>' . $e((string) $item['mode']) . '</code></p>';
            $html .= '<p>' . $e(Translation::text('Paymos.refunds.cash_status', $locale)) . ': <code>' . $e((string) $item['cash_state']) . '</code></p>';
            $html .= '<p>' . $e(Translation::text('Paymos.refunds.blesta_record', $locale)) . ': <code>' . $e((string) $item['projection_state']) . '</code></p>';
            if (!empty($item['amount'])) {
                $html .= '<p>' . $e(Translation::text('Paymos.refunds.amount', $locale)) . ': <code>' . $e((string) $item['amount'] . ' ' . (string) $item['asset']) . '</code></p>';
            }
            if (!empty($item['review_reason'])) {
                $html .= '<p>' . $e(Translation::text('Paymos.refunds.review_reason', $locale)) . ': <code>' . $e((string) $item['review_reason']) . '</code></p>';
            }
            $html .= '<p class="paymos-refund-status-copy">' . $e((string) $item['status_copy']) . '</p>';
            $html .= '</div>';
        }

        foreach ($externalRefunds as $ext) {
            $html .= '<div class="paymos-refund-item paymos-refund-external">';
            $html .= '<p><strong>' . $e(Translation::text('Paymos.refunds.external_badge', $locale)) . '</strong></p>';
            $html .= '<p>' . $e(Translation::text('Paymos.refunds.type', $locale)) . ': <code>' . $e((string) $ext['mode']) . '</code></p>';
            $html .= '<p>' . $e(Translation::text('Paymos.refunds.cash_status', $locale)) . ': <code>' . $e((string) $ext['status']) . '</code></p>';
            $html .= '<p>' . $e(Translation::text('Paymos.refunds.amount', $locale)) . ': <code>' . $e((string) $ext['amount'] . ' ' . (string) $ext['asset']) . '</code></p>';
            $html .= '</div>';
        }

        if ($canRefundFull) {
            $html .= '<button type="button" class="btn btn-default paymos-refund-btn" data-mode="full" data-token="' . $e($tokens[RefundStates::FULL]) . '">'
                . $e(Translation::text('Paymos.refunds.button_full', $locale))
                . '</button> ';
        }
        if ($canRefundOverpayment) {
            $html .= '<button type="button" class="btn btn-default paymos-refund-btn" data-mode="overpayment" data-token="' . $e($tokens[RefundStates::OVERPAYMENT]) . '">'
                . $e(Translation::text('Paymos.refunds.button_overpayment', $locale))
                . '</button>';
        }

        $html .= '</div>';
        return $html;
    }

    private function enforceAuthorization(bool $requireCsrf): void
    {
        if ($this->authorize !== null) {
            call_user_func($this->authorize, $requireCsrf);
        }
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        $clock = $this->clock;
        return new RefundCoordinator(
            $this->client($intent->environment()),
            $this->store,
            $this,
            static function () use ($clock): \DateTimeImmutable {
                return new \DateTimeImmutable('@' . (int) call_user_func($clock));
            }
        );
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $this->config, $environment);
        }

        return new Client($this->config->clientConfig($environment));
    }

    private function ambiguous(RefundIntent $intent, string $reason): NativeProjectionResult
    {
        return new NativeProjectionResult(
            NativeProjectionResult::AMBIGUOUS,
            $intent->mode(),
            null,
            null,
            null,
            null,
            null,
            $reason
        );
    }

    public static function isValidUuidV4(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', trim($value)) === 1;
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
