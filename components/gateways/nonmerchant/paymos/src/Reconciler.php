<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReconciler;
use Paymos\Plugin\StatusMapper;

final class Reconciler
{
    public const THROTTLE_SECONDS = 600;
    public const LOOKBACK_SECONDS = 86400;
    public const BATCH_LIMIT = 50;
    public const LAST_RUN_SETTING = 'reconcile_last_run';

    /** @var Config */
    private $config;

    /** @var CredentialStore */
    private $credentialStore;

    /** @var SnapshotStore */
    private $snapshots;

    /** @var CallbackProcessor */
    private $callbackProcessor;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable */
    private $clock;

    /** @var callable|null */
    private $logger;

    public function __construct(
        Config $config,
        CredentialStore $credentialStore,
        SnapshotStore $snapshots,
        CallbackProcessor $callbackProcessor,
        ?callable $clientFactory = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->credentialStore = $credentialStore;
        $this->snapshots = $snapshots;
        $this->callbackProcessor = $callbackProcessor;
        $this->clientFactory = $clientFactory;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
        $this->logger = $logger;
    }

    public function isDue(?int $now = null): bool
    {
        $ts = $now ?? $this->now();
        $raw = $this->credentialStore->getSetting(self::LAST_RUN_SETTING);
        $lastRun = $raw !== null && is_numeric($raw) ? (int) $raw : 0;

        if ($lastRun > 0 && ($ts - $lastRun) < self::THROTTLE_SECONDS) {
            return false;
        }

        return true;
    }

    /**
     * Sweep up to 50 unpaid Paymos invoices created within the last 24 hours,
     * throttled to once every 600 seconds.
     *
     * @return array{ran: bool, checked: int, applied: int, errors: int}
     */
    public function run(?int $now = null, bool $force = false): array
    {
        $ts = $now ?? $this->now();
        if (!$force && !$this->isDue($ts)) {
            return array('ran' => false, 'checked' => 0, 'applied' => 0, 'errors' => 0);
        }

        $this->credentialStore->putSetting(self::LAST_RUN_SETTING, (string) $ts);

        $rows = $this->snapshots->findUnpaidRecent(self::BATCH_LIMIT, $ts - self::LOOKBACK_SECONDS);
        $checked = 0;
        $applied = 0;
        $errors = 0;

        foreach ($rows as $row) {
            if (CheckoutService::isPendingIntent($row)) {
                continue;
            }
            $invoiceId = isset($row['paymos_invoice_id']) ? trim((string) $row['paymos_invoice_id']) : '';
            $environment = isset($row['environment']) ? trim((string) $row['environment']) : '';
            if ($invoiceId === '' || strncmp($invoiceId, 'pending:', 8) === 0 || !$this->config->hasEnvironment($environment)) {
                continue;
            }

            try {
                $sdkReconciler = new InvoiceReconciler($this->client($environment));
                $results = $sdkReconciler->reconcile(array($invoiceId));
                if (count($results) === 0) {
                    continue;
                }
                $checked++;
                $rec = $results[0];
                $invoice = $rec->invoice();

                if (!$this->snapshotMatches($row, $invoice)) {
                    $this->log('reconcile.snapshot_mismatch', array(
                        'paymos_invoice_id' => $invoiceId,
                        'external_order_id' => $row['external_order_id'] ?? '',
                    ), 'warning');
                    continue;
                }

                $isPaidLate = !empty($invoice['paid_late_at'])
                    && in_array(strtolower($rec->status()), array('expired', 'underpaid'), true);

                if (!$isPaidLate && $rec->action() === StatusMapper::ACTION_IGNORE) {
                    continue;
                }

                if ($this->callbackProcessor->applyTrustedInvoice($invoice, $row, $ts)) {
                    $applied++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->log('reconcile.error', array(
                    'paymos_invoice_id' => $invoiceId,
                    'error' => $this->config->sanitize($e->getMessage()),
                ), 'error');
            }
        }

        return array(
            'ran' => true,
            'checked' => $checked,
            'applied' => $applied,
            'errors' => $errors,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     */
    private function snapshotMatches(array $row, array $invoice): bool
    {
        $projectId = self::field($invoice, array('project_id'));
        $externalId = self::field($invoice, array('order', 'external_id'));
        $amount = self::field($invoice, array('order', 'amount'));
        $currency = strtoupper(self::field($invoice, array('order', 'currency')));

        if (!array_key_exists('is_test', $invoice) || !is_bool($invoice['is_test'])) {
            return false;
        }
        $expectedIsTest = strtolower(trim((string) ($row['environment'] ?? ''))) === 'sandbox';
        if ($invoice['is_test'] !== $expectedIsTest) {
            return false;
        }

        return self::exactRequired((string) ($row['project_id'] ?? ''), $projectId)
            && self::exactRequired((string) ($row['external_order_id'] ?? ''), $externalId)
            && self::amountMatches((string) ($row['amount'] ?? ''), $amount)
            && self::exactRequired(strtoupper((string) ($row['currency'] ?? '')), $currency);
    }

    private static function exactRequired(string $expected, string $actual): bool
    {
        $expected = trim($expected);
        $actual = trim($actual);
        return $expected !== '' && $actual !== '' && $expected === $actual;
    }

    private static function amountMatches(string $expected, string $actual): bool
    {
        $expected = trim($expected);
        $actual = trim($actual);
        return $expected !== '' && $actual !== '' && AmountGuard::amountsEqual($expected, $actual);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private static function field(array $payload, array $path): string
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? trim((string) $current) : '';
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
