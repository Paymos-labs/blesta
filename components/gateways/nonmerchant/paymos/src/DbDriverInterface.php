<?php

declare(strict_types=1);

namespace PaymosBlesta;

interface DbDriverInterface
{
    /**
     * Lowercase SQL driver name (e.g. 'mysql', 'pgsql', 'sqlite', 'memory').
     */
    public function driverName(): string;

    /**
     * Whether the underlying connection is currently inside an active transaction.
     */
    public function inTransaction(): bool;

    /**
     * Read-only metadata check for whether a table already exists (never runs DDL).
     */
    public function tableExists(string $table): bool;

    /**
     * Execute a DDL or DML statement and return the affected row count.
     *
     * @param string $sql
     * @param array<int, mixed> $params
     * @return int
     */
    public function execute(string $sql, array $params = array()): int;

    /**
     * Fetch a single associative row or null when no matching row exists.
     *
     * @param string $sql
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = array()): ?array;

    /**
     * Fetch all matching associative rows.
     *
     * @param string $sql
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = array()): array;

    /**
     * Atomically insert a row protected by a unique or primary key without
     * aborting an active transaction on conflict.
     * Returns true on insert, false strictly on unique/primary conflict, and
     * re-throws all other database errors.
     *
     * @param string $table
     * @param array<string, mixed> $columns
     * @param string|null $conflictColumn Primary/unique key column for ON CONFLICT clauses.
     * @return bool
     */
    public function insertUnique(string $table, array $columns, ?string $conflictColumn = null): bool;

    /**
     * Execute a compare-and-swap UPDATE and return affected row count.
     *
     * @param string $table
     * @param array<string, mixed> $set
     * @param array<string, mixed> $where
     * @param array<string, array{op: string, value: mixed}> $conditions
     * @return int
     */
    public function casUpdate(string $table, array $set, array $where, array $conditions = array()): int;

    /**
     * Delete up to $limit rows whose $column is strictly less than $now.
     *
     * @param string $table
     * @param string $column
     * @param int $now
     * @param int $limit
     * @return int
     */
    public function deleteExpired(string $table, string $column, int $now, int $limit = 100): int;

    public function beginTransaction(): void;

    public function commit(): bool;

    public function rollBack(): void;

    /**
     * Acquire a connection-scoped advisory lock (MySQL GET_LOCK / PostgreSQL advisory lock).
     */
    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool;

    /**
     * Verify this connection still owns the named advisory lock.
     */
    public function isNamedLockOwned(string $name): bool;

    /**
     * Release the named advisory lock owned by this connection.
     */
    public function releaseNamedLock(string $name): void;
}
