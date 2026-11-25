<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Plugin\StatusMapper;

final class SnapshotStore
{
    public const TABLE = 'paymos_invoice_snapshots';
    public const STATUS_BLESTA_INVOICE_MISSING = 'blesta_invoice_missing';

    /** @var DbDriverInterface */
    private $db;

    /** @var callable */
    private $clock;

    /** @var bool */
    private $schemaEnsured = false;

    public function __construct(DbDriverInterface $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        if ($this->db->tableExists(self::TABLE)) {
            $this->schemaEnsured = true;
            return;
        }

        if ($this->db->inTransaction()) {
            return;
        }

        $driver = $this->db->driverName();
        if ($driver === 'mysql') {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'external_order_id VARCHAR(128) PRIMARY KEY, '
                . 'paymos_invoice_id VARCHAR(128) NOT NULL, '
                . 'platform_ref VARCHAR(128) NOT NULL, '
                . 'blesta_invoice_id BIGINT NULL, '
                . 'client_id VARCHAR(64) NULL, '
                . 'payload_meta TEXT NULL, '
                . 'amount VARCHAR(64) NOT NULL, '
                . 'currency VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, '
                . 'project_id VARCHAR(64) NOT NULL, '
                . 'payment_url TEXT NOT NULL, '
                . 'transaction_id VARCHAR(128) NULL, '
                . 'status VARCHAR(32) NOT NULL DEFAULT \'created\', '
                . 'final_status VARCHAR(32) NULL, '
                . 'paid_late_noted VARCHAR(128) NULL, '
                . 'expires_at BIGINT NULL, '
                . 'renewal_attempt INT NOT NULL DEFAULT 0, '
                . 'created_at BIGINT NOT NULL, '
                . 'updated_at BIGINT NOT NULL, '
                . 'INDEX idx_paymos_snapshots_invoice_id (paymos_invoice_id), '
                . 'INDEX idx_paymos_snapshots_transaction_id (transaction_id), '
                . 'INDEX idx_paymos_snapshots_platform_ref (platform_ref), '
                . 'INDEX idx_paymos_snapshots_blesta_inv (blesta_invoice_id), '
                . 'INDEX idx_paymos_snapshots_status_created (status, created_at)'
                . ')'
            );
        } else {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'external_order_id VARCHAR(128) PRIMARY KEY, '
                . 'paymos_invoice_id VARCHAR(128) NOT NULL, '
                . 'platform_ref VARCHAR(128) NOT NULL, '
                . 'blesta_invoice_id BIGINT NULL, '
                . 'client_id VARCHAR(64) NULL, '
                . 'payload_meta TEXT NULL, '
                . 'amount VARCHAR(64) NOT NULL, '
                . 'currency VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, '
                . 'project_id VARCHAR(64) NOT NULL, '
                . 'payment_url TEXT NOT NULL, '
                . 'transaction_id VARCHAR(128) NULL, '
                . 'status VARCHAR(32) NOT NULL DEFAULT \'created\', '
                . 'final_status VARCHAR(32) NULL, '
                . 'paid_late_noted VARCHAR(128) NULL, '
                . 'expires_at BIGINT NULL, '
                . 'renewal_attempt INT NOT NULL DEFAULT 0, '
                . 'created_at BIGINT NOT NULL, '
                . 'updated_at BIGINT NOT NULL'
                . ')'
            );

            if ($driver === 'sqlite' || $driver === 'pgsql') {
                foreach (
                    array(
                        'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_invoice_id ON ' . self::TABLE . ' (paymos_invoice_id)',
                        'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_transaction_id ON ' . self::TABLE . ' (transaction_id)',
                        'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_platform_ref ON ' . self::TABLE . ' (platform_ref)',
                        'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_blesta_inv ON ' . self::TABLE . ' (blesta_invoice_id)',
                        'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_status_created ON ' . self::TABLE . ' (status, created_at)',
                    ) as $indexSql
                ) {
                    $this->db->execute($indexSql);
                }
            }
        }

        $this->schemaEnsured = true;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function save(array $snapshot): void
    {
        $this->ensureSchema();

        $externalOrderId = trim((string) ($snapshot['external_order_id'] ?? ''));
        if ($externalOrderId === '') {
            throw new \InvalidArgumentException('Snapshot external_order_id is required.');
        }

        $now = $this->now();
        $status = isset($snapshot['status']) && is_string($snapshot['status']) && trim($snapshot['status']) !== ''
            ? strtolower(trim($snapshot['status']))
            : 'created';
        $finalStatus = isset($snapshot['final_status']) && is_string($snapshot['final_status']) && trim($snapshot['final_status']) !== ''
            ? strtolower(trim($snapshot['final_status']))
            : (StatusMapper::isFinalStatus($status) ? $status : null);

        $row = array(
            'external_order_id' => $externalOrderId,
            'paymos_invoice_id' => (string) ($snapshot['paymos_invoice_id'] ?? ''),
            'platform_ref' => (string) ($snapshot['platform_ref'] ?? ''),
            'blesta_invoice_id' => isset($snapshot['blesta_invoice_id']) && $snapshot['blesta_invoice_id'] !== null && $snapshot['blesta_invoice_id'] !== ''
                ? (int) $snapshot['blesta_invoice_id']
                : null,
            'client_id' => isset($snapshot['client_id']) && $snapshot['client_id'] !== '' ? (string) $snapshot['client_id'] : null,
            'payload_meta' => isset($snapshot['payload_meta']) && $snapshot['payload_meta'] !== null ? (string) $snapshot['payload_meta'] : null,
            'amount' => (string) ($snapshot['amount'] ?? ''),
            'currency' => strtoupper(trim((string) ($snapshot['currency'] ?? ''))),
            'environment' => (string) ($snapshot['environment'] ?? ''),
            'project_id' => (string) ($snapshot['project_id'] ?? ''),
            'payment_url' => (string) ($snapshot['payment_url'] ?? ''),
            'transaction_id' => isset($snapshot['transaction_id']) && $snapshot['transaction_id'] !== '' ? (string) $snapshot['transaction_id'] : null,
            'status' => $status,
            'final_status' => $finalStatus,
            'paid_late_noted' => isset($snapshot['paid_late_noted']) && $snapshot['paid_late_noted'] !== '' ? (string) $snapshot['paid_late_noted'] : null,
            'expires_at' => isset($snapshot['expires_at']) && $snapshot['expires_at'] !== null ? (int) $snapshot['expires_at'] : null,
            'renewal_attempt' => isset($snapshot['renewal_attempt']) ? (int) $snapshot['renewal_attempt'] : 0,
            'created_at' => isset($snapshot['created_at']) ? (int) $snapshot['created_at'] : $now,
            'updated_at' => isset($snapshot['updated_at']) ? (int) $snapshot['updated_at'] : $now,
        );

        if ($this->db->insertUnique(self::TABLE, $row, 'external_order_id')) {
            return;
        }

        $updatable = $row;
        unset($updatable['external_order_id'], $updatable['created_at']);

        $this->db->casUpdate(
            self::TABLE,
            $updatable,
            array('external_order_id' => $externalOrderId)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalOrderId(string $externalOrderId): ?array
    {
        $externalOrderId = trim($externalOrderId);
        if ($externalOrderId === '') {
            return null;
        }

        $this->ensureSchema();

        return $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE external_order_id = ?',
            array($externalOrderId)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByInvoiceId(string $paymosInvoiceId): ?array
    {
        $paymosInvoiceId = trim($paymosInvoiceId);
        if ($paymosInvoiceId === '') {
            return null;
        }

        $this->ensureSchema();

        return $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE paymos_invoice_id = ? ORDER BY updated_at DESC LIMIT 1',
            array($paymosInvoiceId)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTransactionId(string $transactionId): ?array
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return null;
        }

        $this->ensureSchema();

        return $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE transaction_id = ? ORDER BY updated_at DESC LIMIT 1',
            array($transactionId)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestByPlatformRef(string $platformRef): ?array
    {
        $platformRef = trim($platformRef);
        if ($platformRef === '') {
            return null;
        }

        $this->ensureSchema();

        return $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE
            . ' WHERE platform_ref = ?'
            . ' ORDER BY renewal_attempt DESC, updated_at DESC LIMIT 1',
            array($platformRef)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestByBlestaInvoiceId(int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }

        $this->ensureSchema();

        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE
            . ' WHERE blesta_invoice_id = ?'
            . ' ORDER BY renewal_attempt DESC, updated_at DESC LIMIT 1',
            array($invoiceId)
        );
        if ($row !== null) {
            return $row;
        }

        return $this->findLatestByPlatformRef('inv_' . $invoiceId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPaidByBlestaInvoiceId(int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }

        $this->ensureSchema();

        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE
            . " WHERE blesta_invoice_id = ? AND status IN ('paid', 'paid_over')"
            . ' ORDER BY updated_at DESC LIMIT 1',
            array($invoiceId)
        );
        if ($row !== null) {
            return $row;
        }

        return $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE
            . " WHERE platform_ref = ? AND status IN ('paid', 'paid_over')"
            . ' ORDER BY updated_at DESC LIMIT 1',
            array('inv_' . $invoiceId)
        );
    }

    /**
     * Find recent paid Paymos invoices for the staff Refunds overview page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findRecentPaid(int $limit = 25): array
    {
        $this->ensureSchema();
        $safeLimit = max(1, min(100, $limit));

        return $this->db->fetchAll(
            'SELECT * FROM ' . self::TABLE
            . " WHERE status IN ('paid', 'paid_over')"
            . ' ORDER BY updated_at DESC LIMIT ' . $safeLimit
        );
    }

    /**
     * Find unpaid, non-terminal, non-creating invoices created within the lookback window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findUnpaidRecent(int $limit = 50, ?int $sinceTimestamp = null): array
    {
        $this->ensureSchema();
        $safeLimit = max(1, min(100, $limit));
        $since = $sinceTimestamp !== null ? $sinceTimestamp : ($this->now() - 86400);

        return $this->db->fetchAll(
            'SELECT * FROM ' . self::TABLE
            . " WHERE status NOT IN ('creating', 'paid', 'paid_over', 'underpaid', 'expired', 'cancelled', 'blesta_invoice_missing')"
            . ' AND created_at >= ?'
            . ' ORDER BY created_at ASC LIMIT ' . $safeLimit,
            array($since)
        );
    }

    public function updateStatus(string $paymosInvoiceId, string $status, ?string $transactionId = null): void
    {
        $paymosInvoiceId = trim($paymosInvoiceId);
        $status = strtolower(trim($status));
        if ($paymosInvoiceId === '' || $status === '') {
            return;
        }

        $this->ensureSchema();

        $set = array(
            'status' => $status,
            'updated_at' => $this->now(),
        );

        if (StatusMapper::isFinalStatus($status)) {
            $set['final_status'] = $status;
        }

        if ($transactionId !== null && trim($transactionId) !== '') {
            $set['transaction_id'] = trim($transactionId);
        }

        $this->db->casUpdate(
            self::TABLE,
            $set,
            array('paymos_invoice_id' => $paymosInvoiceId)
        );
    }

    public function markPaidLateNoted(string $externalOrderId, string $marker): void
    {
        $externalOrderId = trim($externalOrderId);
        $marker = trim($marker);
        if ($externalOrderId === '' || $marker === '') {
            return;
        }

        $this->ensureSchema();

        $this->db->casUpdate(
            self::TABLE,
            array(
                'paid_late_noted' => $marker,
                'updated_at' => $this->now(),
            ),
            array('external_order_id' => $externalOrderId)
        );
    }

    public function markPaymentOutcome(string $externalOrderId, ?string $finalStatus, ?string $transactionId = null): void
    {
        $this->ensureSchema();

        $normalized = $finalStatus !== null && trim($finalStatus) !== '' ? strtolower(trim($finalStatus)) : null;
        $set = array(
            'final_status' => $normalized,
            'updated_at' => $this->now(),
        );
        if ($normalized !== null) {
            $set['status'] = $normalized;
        }
        if ($transactionId !== null && trim($transactionId) !== '') {
            $set['transaction_id'] = trim($transactionId);
        }

        $this->db->casUpdate(
            self::TABLE,
            $set,
            array('external_order_id' => $externalOrderId)
        );
    }

    /**
     * @param mixed $value
     */
    public function updateMetaFlag(string $externalOrderId, string $key, $value): void
    {
        $snapshot = $this->findByExternalOrderId($externalOrderId);
        if ($snapshot === null) {
            return;
        }

        $meta = self::decodeMeta(isset($snapshot['payload_meta']) ? (string) $snapshot['payload_meta'] : null);
        $meta[$key] = $value;

        $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }

        $this->db->casUpdate(
            self::TABLE,
            array(
                'payload_meta' => $encoded,
                'updated_at' => $this->now(),
            ),
            array('external_order_id' => $externalOrderId)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeMeta(?string $rawMeta): array
    {
        if ($rawMeta === null || trim($rawMeta) === '') {
            return array();
        }

        $decoded = json_decode($rawMeta, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
