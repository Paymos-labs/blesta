<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Webhook\CommitAwareEventStoreInterface;

final class PdoEventStore implements CommitAwareEventStoreInterface
{
    public const TABLE = 'paymos_webhook_events';
    public const RESERVATION_SECONDS = 300;
    public const CLEANUP_BATCH_LIMIT = 100;

    /** @var DbDriverInterface */
    private $db;

    /** @var callable */
    private $clock;

    /** @var array<string, array{token: string, ttl: int}> */
    private $ownerTokens = array();

    /** @var string|null */
    private $lastEventId;

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
                . 'event_id VARCHAR(128) PRIMARY KEY, '
                . 'owner_token VARCHAR(64) NOT NULL, '
                . 'state VARCHAR(16) NOT NULL, '
                . 'expires_at BIGINT NOT NULL, '
                . 'created_at BIGINT NOT NULL, '
                . 'INDEX idx_paymos_webhook_events_expires (expires_at)'
                . ')'
            );
        } else {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'event_id VARCHAR(128) PRIMARY KEY, '
                . 'owner_token VARCHAR(64) NOT NULL, '
                . 'state VARCHAR(16) NOT NULL, '
                . 'expires_at BIGINT NOT NULL, '
                . 'created_at BIGINT NOT NULL'
                . ')'
            );

            if ($driver === 'sqlite' || $driver === 'pgsql') {
                $this->db->execute(
                    'CREATE INDEX IF NOT EXISTS idx_paymos_webhook_events_expires ON ' . self::TABLE . ' (expires_at)'
                );
            }
        }

        $this->schemaEnsured = true;
    }

    /**
     * @param string $eventId
     * @param int $ttlSeconds
     * @return bool
     */
    public function remember($eventId, $ttlSeconds)
    {
        $eventId = trim((string) $eventId);
        $ttlSeconds = (int) $ttlSeconds;

        if ($eventId === '' || $ttlSeconds < 1) {
            throw new \InvalidArgumentException('eventId must be non-empty and ttlSeconds must be positive.');
        }

        $this->ensureSchema();
        $now = $this->now();

        $this->db->deleteExpired(self::TABLE, 'expires_at', $now, self::CLEANUP_BATCH_LIMIT);

        $ownerToken = bin2hex(random_bytes(16));
        $reservationExpiresAt = $now + self::RESERVATION_SECONDS;

        $inserted = $this->db->insertUnique(self::TABLE, array(
            'event_id' => $eventId,
            'owner_token' => $ownerToken,
            'state' => 'processing',
            'expires_at' => $reservationExpiresAt,
            'created_at' => $now,
        ), 'event_id');

        if (!$inserted) {
            $takenOver = $this->db->casUpdate(
                self::TABLE,
                array(
                    'owner_token' => $ownerToken,
                    'state' => 'processing',
                    'expires_at' => $reservationExpiresAt,
                    'created_at' => $now,
                ),
                array(
                    'event_id' => $eventId,
                    'state' => 'processing',
                ),
                array(
                    'expires_at' => array('op' => '<=', 'value' => $now),
                )
            );

            if ($takenOver !== 1) {
                $expiredCommittedTakenOver = $this->db->casUpdate(
                    self::TABLE,
                    array(
                        'owner_token' => $ownerToken,
                        'state' => 'processing',
                        'expires_at' => $reservationExpiresAt,
                        'created_at' => $now,
                    ),
                    array(
                        'event_id' => $eventId,
                        'state' => 'committed',
                    ),
                    array(
                        'expires_at' => array('op' => '<=', 'value' => $now),
                    )
                );

                if ($expiredCommittedTakenOver !== 1) {
                    return false;
                }
            }
        }

        $this->ownerTokens[$eventId] = array(
            'token' => $ownerToken,
            'ttl' => $ttlSeconds,
        );
        $this->lastEventId = $eventId;

        return true;
    }

    /**
     * @param string $eventId
     * @return bool
     */
    public function isCommitted($eventId)
    {
        $eventId = trim((string) $eventId);
        if ($eventId === '') {
            return false;
        }

        $this->ensureSchema();

        $row = $this->db->fetchOne(
            'SELECT state, expires_at FROM ' . self::TABLE . ' WHERE event_id = ?',
            array($eventId)
        );

        if ($row === null) {
            return false;
        }

        return isset($row['state'], $row['expires_at'])
            && (string) $row['state'] === 'committed'
            && (int) $row['expires_at'] > $this->now();
    }

    public function commit(?string $eventId = null): bool
    {
        $target = $eventId !== null ? $eventId : $this->lastEventId;
        if ($target === null || !isset($this->ownerTokens[$target])) {
            return false;
        }

        $entry = $this->ownerTokens[$target];
        unset($this->ownerTokens[$target]);
        if ($this->lastEventId === $target) {
            $this->lastEventId = null;
        }

        $this->ensureSchema();
        $now = $this->now();

        $affected = $this->db->casUpdate(
            self::TABLE,
            array(
                'state' => 'committed',
                'expires_at' => $now + $entry['ttl'],
            ),
            array(
                'event_id' => $target,
                'owner_token' => $entry['token'],
                'state' => 'processing',
            )
        );

        return $affected === 1;
    }

    public function release(?string $eventId = null): void
    {
        $target = $eventId !== null ? $eventId : $this->lastEventId;
        if ($target === null || !isset($this->ownerTokens[$target])) {
            return;
        }

        $entry = $this->ownerTokens[$target];
        unset($this->ownerTokens[$target]);
        if ($this->lastEventId === $target) {
            $this->lastEventId = null;
        }

        $this->ensureSchema();

        $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE event_id = ? AND state = ? AND owner_token = ?',
            array($target, 'processing', $entry['token'])
        );
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
