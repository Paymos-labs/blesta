<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Exception\EventInProgressException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundCandidateStoreInterface;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundRecipientIdentity;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

final class RefundIntentStore implements RefundIntentStoreInterface, RefundCandidateStoreInterface
{
    public const TABLE = 'paymos_refund_intents';
    public const EXTERNAL_TABLE = 'paymos_refund_external';

    /** @var DbDriverInterface */
    private $db;

    /** @var CredentialStore */
    private $credentialStore;

    /** @var callable */
    private $clock;

    /** @var string|null */
    private $lockedPaymentKey;

    /** @var int */
    private $txDepth = 0;

    /** @var bool */
    private $schemaEnsured = false;

    public function __construct(
        DbDriverInterface $db,
        CredentialStore $credentialStore,
        ?callable $clock = null
    ) {
        $this->db = $db;
        $this->credentialStore = $credentialStore;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $intentsExists = $this->db->tableExists(self::TABLE);
        $externalExists = $this->db->tableExists(self::EXTERNAL_TABLE);
        if ($intentsExists && $externalExists) {
            $this->schemaEnsured = true;
            return;
        }

        if ($this->db->inTransaction()) {
            throw new \RuntimeException('refund_schema_transaction_conflict');
        }

        $driver = $this->db->driverName();
        $payloadType = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';

        if (!$intentsExists) {
            if ($driver === 'mysql') {
                $this->db->execute(
                    'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                    . 'operation_id VARCHAR(36) PRIMARY KEY, '
                    . 'installation_id VARCHAR(36) NOT NULL, '
                    . 'request_token VARCHAR(64) NOT NULL, '
                    . 'external_order_id VARCHAR(200) NOT NULL, '
                    . 'native_payment_key VARCHAR(64) NOT NULL, '
                    . 'withdrawal_id VARCHAR(128) NULL, '
                    . 'payload ' . $payloadType . ' NOT NULL, '
                    . 'version BIGINT NOT NULL, '
                    . 'created_at BIGINT NOT NULL, '
                    . 'next_check BIGINT NOT NULL, '
                    . 'UNIQUE KEY uniq_paymos_refund_token (installation_id, request_token), '
                    . 'UNIQUE KEY uniq_paymos_refund_external (external_order_id), '
                    . 'UNIQUE KEY uniq_paymos_refund_withdrawal (withdrawal_id), '
                    . 'INDEX idx_paymos_refund_payment (native_payment_key), '
                    . 'INDEX idx_paymos_refund_due (next_check, operation_id)'
                    . ') ENGINE=InnoDB'
                );
            } else {
                $this->db->execute(
                    'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                    . 'operation_id VARCHAR(36) PRIMARY KEY, '
                    . 'installation_id VARCHAR(36) NOT NULL, '
                    . 'request_token VARCHAR(64) NOT NULL, '
                    . 'external_order_id VARCHAR(200) NOT NULL UNIQUE, '
                    . 'native_payment_key VARCHAR(64) NOT NULL, '
                    . 'withdrawal_id VARCHAR(128) NULL UNIQUE, '
                    . 'payload ' . $payloadType . ' NOT NULL, '
                    . 'version BIGINT NOT NULL, '
                    . 'created_at BIGINT NOT NULL, '
                    . 'next_check BIGINT NOT NULL, '
                    . 'UNIQUE (installation_id, request_token)'
                    . ')'
                );
                if ($driver === 'sqlite' || $driver === 'pgsql') {
                    $this->db->execute('CREATE INDEX IF NOT EXISTS idx_paymos_refund_payment ON ' . self::TABLE . ' (native_payment_key)');
                    $this->db->execute('CREATE INDEX IF NOT EXISTS idx_paymos_refund_due ON ' . self::TABLE . ' (next_check, operation_id)');
                }
            }
        }

        if (!$externalExists) {
            if ($driver === 'mysql') {
                $this->db->execute(
                    'CREATE TABLE IF NOT EXISTS ' . self::EXTERNAL_TABLE . ' ('
                    . 'withdrawal_id VARCHAR(128) PRIMARY KEY, '
                    . 'native_payment_key VARCHAR(64) NOT NULL, '
                    . 'invoice_id VARCHAR(64) NOT NULL, '
                    . 'payload ' . $payloadType . ' NOT NULL, '
                    . 'updated_at BIGINT NOT NULL, '
                    . 'INDEX idx_paymos_refund_ext_payment (native_payment_key), '
                    . 'INDEX idx_paymos_refund_ext_invoice (invoice_id)'
                    . ') ENGINE=InnoDB'
                );
            } else {
                $this->db->execute(
                    'CREATE TABLE IF NOT EXISTS ' . self::EXTERNAL_TABLE . ' ('
                    . 'withdrawal_id VARCHAR(128) PRIMARY KEY, '
                    . 'native_payment_key VARCHAR(64) NOT NULL, '
                    . 'invoice_id VARCHAR(64) NOT NULL, '
                    . 'payload ' . $payloadType . ' NOT NULL, '
                    . 'updated_at BIGINT NOT NULL'
                    . ')'
                );
                if ($driver === 'sqlite' || $driver === 'pgsql') {
                    $this->db->execute('CREATE INDEX IF NOT EXISTS idx_paymos_refund_ext_payment ON ' . self::EXTERNAL_TABLE . ' (native_payment_key)');
                    $this->db->execute('CREATE INDEX IF NOT EXISTS idx_paymos_refund_ext_invoice ON ' . self::EXTERNAL_TABLE . ' (invoice_id)');
                }
            }
        }

        $this->schemaEnsured = true;
    }

    public function installationId(): string
    {
        return $this->credentialStore->installationId();
    }

    public static function uuid(): string
    {
        return CredentialStore::uuidV4();
    }

    public function withPaymentLock(string $paymentKey, callable $action)
    {
        if (preg_match('/\A[1-9][0-9]*\z/', $paymentKey) !== 1) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }

        if ($this->lockedPaymentKey !== null) {
            if ($this->lockedPaymentKey !== $paymentKey) {
                throw new \RuntimeException('refund_lock_scope_conflict');
            }
            $this->assertOwned();
            return $action();
        }

        $lockName = $this->lockName($paymentKey);
        if (!$this->db->acquireNamedLock($lockName, 10)) {
            throw new EventInProgressException('refund_payment_busy');
        }

        $this->lockedPaymentKey = $paymentKey;
        try {
            return $action();
        } finally {
            $this->lockedPaymentKey = null;
            $this->db->releaseNamedLock($lockName);
        }
    }

    public function assertOwned(): void
    {
        if ($this->lockedPaymentKey === null
            || !$this->db->isNamedLockOwned($this->lockName($this->lockedPaymentKey))) {
            throw new \RuntimeException('refund_payment_lock_lost');
        }
    }

    public function transaction(callable $action)
    {
        $this->assertOwned();
        if ($this->txDepth > 0) {
            return $action();
        }
        if ($this->db->inTransaction()) {
            throw new \RuntimeException('refund_foreign_transaction');
        }

        $this->db->beginTransaction();
        $this->txDepth = 1;
        try {
            $result = $action();
            $this->assertOwned();
            if (!$this->db->commit()) {
                throw new \RuntimeException('refund_commit_unknown');
            }
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        } finally {
            $this->txDepth = 0;
        }
    }

    /**
     * Execute a native Blesta model call outside the active store transaction
     * while preserving payment lock ownership.
     */
    public function outsideTransaction(callable $action)
    {
        $this->assertOwned();
        $resume = $this->txDepth > 0;
        if (!$resume && $this->db->inTransaction()) {
            throw new \RuntimeException('refund_foreign_transaction');
        }

        if ($resume) {
            if (!$this->db->commit()) {
                throw new \RuntimeException('refund_commit_unknown');
            }
            $this->txDepth = 0;
        }

        try {
            $result = $action();
            $this->assertOwned();
            return $result;
        } finally {
            if ($resume) {
                $this->assertOwned();
                $this->db->beginTransaction();
                $this->txDepth = 1;
            }
        }
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        $this->ensureSchema();
        $this->assertIntentLock($intent);
        if ($this->txDepth !== 0) {
            throw new \RuntimeException('refund_intent_must_commit');
        }

        return $this->transaction(function () use ($intent): RefundIntent {
            $data = $intent->toArray();
            $existing = $this->findByRequestToken($data['installation_id'], $data['request_token']);
            if ($existing !== null) {
                $existingData = $existing->toArray();
                foreach (array('platform', 'native_payment_key', 'invoice_id', 'project_id', 'environment', 'mode', 'full_draft') as $field) {
                    if (($existingData[$field] ?? null) !== ($data[$field] ?? null)) {
                        throw new \RuntimeException('refund_request_conflict');
                    }
                }
                return $existing;
            }

            // One Blesta invoice may hold several Paymos payments under the same key: only an
            // intent of the same Paymos invoice blocks a new one.
            foreach ($this->forPayment($intent->paymentKey()) as $old) {
                if ($old->invoiceId() === $intent->invoiceId() && $this->blocksNewIntent($old)) {
                    throw new \RuntimeException('refund_payment_has_unresolved_intent');
                }
            }

            $now = $this->now();
            $data = array_replace($data, array(
                'created_at' => $now,
                'checks' => 0,
                'version' => 1,
            ));

            $inserted = $this->db->insertUnique(self::TABLE, array(
                'operation_id' => $intent->operationId(),
                'installation_id' => $data['installation_id'],
                'request_token' => $data['request_token'],
                'external_order_id' => $intent->externalOrderId(),
                'native_payment_key' => $intent->paymentKey(),
                'withdrawal_id' => null,
                'payload' => $this->encode($data),
                'version' => 1,
                'created_at' => $now,
                'next_check' => $now + 60,
            ), 'operation_id');

            if (!$inserted) {
                throw new \RuntimeException('refund_request_conflict');
            }

            return new RefundIntent($data);
        });
    }

    public function blocksNewIntent(RefundIntent $intent): bool
    {
        return $intent->projectionState() === RefundStates::NATIVE_REVIEW
            || ($intent->mode() === RefundStates::FULL
                && ($intent->cashState() === RefundStates::COMPLETED
                    || $intent->toArray()['projection'] !== null))
            || !in_array($intent->cashState(), array(RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED), true)
            || ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() !== RefundStates::PROJECTION_APPLIED);
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        $this->ensureSchema();
        return $this->one('installation_id = ? AND request_token = ?', array($installationId, $token));
    }

    public function getIntent(string $operationId): RefundIntent
    {
        $this->ensureSchema();
        $intent = $this->one('operation_id = ?', array($operationId));
        if ($intent === null) {
            throw new \RuntimeException('refund_intent_missing');
        }

        return $intent;
    }

    public function findByExternalId(string $key): ?RefundIntent
    {
        $this->ensureSchema();
        return $this->one('external_order_id = ?', array($key));
    }

    public function findByWithdrawalId(string $id): ?RefundIntent
    {
        $this->ensureSchema();
        return $this->one('withdrawal_id = ?', array($id));
    }

    /**
     * @return array<int, RefundIntent>
     */
    public function forPayment(string $paymentKey): array
    {
        $this->ensureSchema();
        return $this->intents('native_payment_key = ? ORDER BY created_at ASC, operation_id ASC', array($paymentKey));
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        $this->ensureSchema();
        return $this->transaction(function () use ($operationId, $id): string {
            $intent = $this->getIntent($operationId);
            $this->assertIntentLock($intent);

            $other = $this->findByWithdrawalId($id);
            if ($id === ''
                || ($other !== null && $other->operationId() !== $operationId)
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }

            if ($intent->withdrawalId() === $id) {
                return 'same';
            }

            $old = $intent->toArray();
            $nextVersion = ((int) ($old['version'] ?? 1)) + 1;
            $data = array_replace($old, array(
                'withdrawal_id' => $id,
                'version' => $nextVersion,
            ));

            $affected = $this->db->casUpdate(
                self::TABLE,
                array(
                    'withdrawal_id' => $id,
                    'payload' => $this->encode($data),
                    'version' => $nextVersion,
                ),
                array(
                    'operation_id' => $operationId,
                    'version' => (int) ($old['version'] ?? 1),
                    'withdrawal_id' => null,
                )
            );

            if ($affected !== 1) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }

            return 'bound';
        });
    }

    public function recordReadCandidate(string $operationId, ?string $candidateWithdrawalId, int $retryCount): void
    {
        $this->ensureSchema();
        $this->transaction(function () use ($operationId, $candidateWithdrawalId, $retryCount): void {
            $intent = $this->getIntent($operationId);
            $this->save($intent, array(
                'candidate_withdrawal_id' => $candidateWithdrawalId !== null && trim($candidateWithdrawalId) !== ''
                    ? trim($candidateWithdrawalId)
                    : null,
                'read_retry_count' => max(0, $retryCount),
            ));
        });
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        $this->ensureSchema();
        $this->transaction(function () use ($operationId, $snapshot): void {
            $intent = $this->getIntent($operationId);
            $pin = $intent->observation();
            if ($intent->withdrawalId() !== $snapshot->withdrawalId()
                || $intent->invoiceId() !== $snapshot->invoiceId()
                || $intent->projectId() !== $snapshot->projectId()
                || $intent->environment() !== $snapshot->environment()
                || $intent->mode() !== $snapshot->mode()
                || $intent->externalOrderId() !== $snapshot->externalOrderId()
                || ($pin !== null && (!AmountGuard::amountsEqual($pin->principalDecimal(), $snapshot->principalDecimal())
                    || $pin->currency() !== $snapshot->currency()
                    || $pin->network() !== $snapshot->network()
                    || !RefundRecipientIdentity::same($pin, $snapshot)))) {
                throw new \RuntimeException('refund_observation_conflict');
            }

            $this->save($intent, array('observation' => $snapshot->toArray()));
        });
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->ensureSchema();
        $this->transaction(function () use ($operationId, $result): void {
            $intent = $this->getIntent($operationId);
            $draft = $intent->fullDraft();
            $snapshot = $intent->observation();
            $old = $intent->toArray()['projection'];

            if ($result->kind() !== NativeProjectionResult::EXACT_MATCH
                || $result->mode() !== $intent->mode()
                || $result->nativeId() === null || $result->nativeId() === ''
                || $snapshot === null || $snapshot->status() !== 'completed'
                || ($old !== null && $old !== $result->toArray())
                || ($draft !== null && ($result->nativeAmountMinor() !== $draft['amount_minor']
                    || $result->nativeCurrency() !== $draft['currency']
                    || $result->cryptoObservationAmount() !== null
                    || $result->cryptoAsset() !== null))
                || ($draft === null && ($result->nativeAmountMinor() !== null
                    || $result->nativeCurrency() !== null
                    || !AmountGuard::amountsEqual($result->cryptoObservationAmount(), $snapshot->principalDecimal())
                    || $result->cryptoAsset() !== $snapshot->currency() . '/' . $snapshot->network()))) {
                throw new \RuntimeException('refund_projection_conflict');
            }

            $this->save($intent, array('projection' => $result->toArray()));
        });
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        if (!in_array($cashState, array(
            RefundStates::NOT_SUBMITTED, RefundStates::UNKNOWN, RefundStates::CREATED,
            RefundStates::PROCESSING, RefundStates::COMPLETED, RefundStates::FAILED,
            RefundStates::CANCELLED, RefundStates::REVIEW,
        ), true)
            || !in_array($projectionState, array(
                RefundStates::PROJECTION_NONE, RefundStates::PROJECTION_PENDING,
                RefundStates::PROJECTION_APPLIED, RefundStates::NATIVE_REVIEW,
            ), true)) {
            throw new \InvalidArgumentException('refund_state_invalid');
        }

        $this->ensureSchema();
        $this->transaction(function () use ($operationId, $cashState, $projectionState, $reason): void {
            $this->save($this->getIntent($operationId), array(
                'cash_state' => $cashState,
                'projection_state' => $projectionState,
                'review_reason' => $reason,
            ));
        });
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        if ($kind === '' || $generation < 1 || $this->txDepth !== 0) {
            throw new \RuntimeException('refund_effect_must_commit');
        }

        $this->ensureSchema();
        return $this->transaction(function () use ($operationId, $kind, $generation): EffectClaim {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (isset($effects[$key]) && $effects[$key] !== RefundStates::EFFECT_PENDING) {
                return new EffectClaim(EffectClaim::ALREADY_STARTED);
            }
            $effects[$key] = RefundStates::EFFECT_STARTED;
            $this->save($intent, array('effects' => $effects));
            return new EffectClaim(EffectClaim::CLAIMED);
        });
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        if ($kind === '' || $generation < 1
            || !in_array($state, array(
                RefundStates::EFFECT_DELIVERED,
                RefundStates::EFFECT_FAILED_NO_EFFECT,
                RefundStates::EFFECT_UNKNOWN,
                RefundStates::EFFECT_SKIPPED,
            ), true)) {
            throw new \InvalidArgumentException('refund_effect_invalid');
        }

        $this->ensureSchema();
        $this->transaction(function () use ($operationId, $kind, $generation, $state): void {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (!isset($effects[$key]) || $effects[$key] !== RefundStates::EFFECT_STARTED) {
                throw new \RuntimeException('refund_effect_not_started');
            }
            $effects[$key] = $state;
            $this->save($intent, array('effects' => $effects));
        });
    }

    public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        $this->ensureSchema();
        $safeLimit = max(1, min(100, $limit));
        $items = $this->intents(
            'next_check <= ? AND operation_id > ? ORDER BY operation_id ASC LIMIT ' . $safeLimit,
            array($now->getTimestamp(), $cursor !== null ? $cursor : '')
        );

        $nextCursor = count($items) === $safeLimit ? $items[count($items) - 1]->operationId() : null;
        return new RefundIntentPage($items, $nextCursor);
    }

    public function defer(string $operationId, ?int $now = null): void
    {
        $this->ensureSchema();
        $ts = $now ?? $this->now();
        $this->transaction(function () use ($operationId, $ts): void {
            $intent = $this->getIntent($operationId);
            $data = $intent->toArray();
            $historical = in_array($intent->cashState(), array(
                RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED, RefundStates::REVIEW,
            ), true);

            $checks = ((int) ($data['checks'] ?? 0)) + 1;
            $changes = array('checks' => $checks);
            $createdAt = (int) ($data['created_at'] ?? $ts);

            if (!$historical && ($ts - $createdAt) > 86400) {
                $changes['projection_state'] = RefundStates::NATIVE_REVIEW;
                $changes['review_reason'] = 'refund_unresolved_over_24h';
                $historical = true;
            }

            $delays = array(60, 120, 300, 900, 3600);
            $delay = $historical ? 86400 : $delays[min($checks, count($delays) - 1)];
            $this->save($intent, $changes, $ts + $delay);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findExternalByWithdrawalId(string $withdrawalId): ?array
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            'SELECT payload FROM ' . self::EXTERNAL_TABLE . ' WHERE withdrawal_id = ?',
            array($withdrawalId)
        );
        if ($row === null || !isset($row['payload']) || !is_string($row['payload'])) {
            return null;
        }
        $decoded = json_decode($row['payload'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function externalForPayment(string $paymentKey): array
    {
        $this->ensureSchema();
        $rows = $this->db->fetchAll(
            'SELECT payload FROM ' . self::EXTERNAL_TABLE . ' WHERE native_payment_key = ? ORDER BY updated_at ASC',
            array($paymentKey)
        );

        $result = array();
        foreach ($rows as $row) {
            if (isset($row['payload']) && is_string($row['payload'])) {
                $decoded = json_decode($row['payload'], true);
                if (is_array($decoded)) {
                    $result[] = $decoded;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function saveExternalObservation(string $withdrawalId, string $paymentKey, string $invoiceId, array $row): void
    {
        $this->ensureSchema();
        $this->assertOwned();
        if ($this->lockedPaymentKey !== $paymentKey) {
            throw new \RuntimeException('refund_lock_scope_conflict');
        }

        $now = $this->now();
        $encoded = $this->encode($row);
        $inserted = $this->db->insertUnique(self::EXTERNAL_TABLE, array(
            'withdrawal_id' => $withdrawalId,
            'native_payment_key' => $paymentKey,
            'invoice_id' => $invoiceId,
            'payload' => $encoded,
            'updated_at' => $now,
        ), 'withdrawal_id');

        if (!$inserted) {
            $this->db->casUpdate(
                self::EXTERNAL_TABLE,
                array(
                    'payload' => $encoded,
                    'updated_at' => $now,
                ),
                array('withdrawal_id' => $withdrawalId)
            );
        }
    }

    private function lockName(string $paymentKey): string
    {
        return 'paymos-blesta-inv:' . $paymentKey;
    }

    private function assertIntentLock(RefundIntent $intent): void
    {
        $this->assertOwned();
        if ($this->lockedPaymentKey !== $intent->paymentKey()) {
            throw new \RuntimeException('refund_lock_scope_conflict');
        }
    }

    /**
     * @param array<int, mixed> $values
     */
    private function one(string $where, array $values): ?RefundIntent
    {
        $rows = $this->intents($where, $values);
        if (count($rows) > 1) {
            throw new \RuntimeException('refund_identity_ambiguous');
        }

        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, RefundIntent>
     */
    private function intents(string $where, array $values): array
    {
        $rows = $this->db->fetchAll('SELECT payload FROM ' . self::TABLE . ' WHERE ' . $where, $values);
        $result = array();
        foreach ($rows as $row) {
            if (!isset($row['payload']) || !is_string($row['payload'])) {
                throw new \RuntimeException('refund_store_corrupt');
            }
            $data = json_decode($row['payload'], true);
            if (!is_array($data)) {
                throw new \RuntimeException('refund_store_corrupt');
            }
            $result[] = new RefundIntent($data);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function save(RefundIntent $intent, array $changes, ?int $nextCheck = null): void
    {
        $this->assertIntentLock($intent);
        $old = $intent->toArray();
        $oldVersion = (int) ($old['version'] ?? 1);
        $nextVersion = $oldVersion + 1;
        $data = array_replace($old, $changes, array('version' => $nextVersion));

        $set = array(
            'payload' => $this->encode($data),
            'withdrawal_id' => $data['withdrawal_id'],
            'version' => $nextVersion,
        );
        if ($nextCheck !== null) {
            $set['next_check'] = $nextCheck;
        }

        $affected = $this->db->casUpdate(
            self::TABLE,
            $set,
            array(
                'operation_id' => $intent->operationId(),
                'version' => $oldVersion,
            )
        );

        if ($affected !== 1) {
            throw new \RuntimeException('refund_store_write_conflict');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('refund_store_encoding_failed');
        }

        return $json;
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
