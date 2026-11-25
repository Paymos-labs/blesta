<?php

declare(strict_types=1);

namespace PaymosBlesta;

final class PdoDbDriver implements DbDriverInterface
{
    private const ALLOWED_OPERATORS = array('=', '!=', '<>', '<', '<=', '>', '>=');

    /** @var \PDO */
    private $pdo;

    /** @var array<string, bool> */
    private $heldLocks = array();

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function driverName(): string
    {
        return strtolower((string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function tableExists(string $table): bool
    {
        self::assertIdentifier($table);
        $driver = $this->driverName();

        if ($driver === 'mysql') {
            $row = $this->fetchOne(
                'SELECT 1 AS found FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
                array($table)
            );
            return $row !== null;
        }

        if ($driver === 'pgsql') {
            $row = $this->fetchOne(
                'SELECT 1 AS found FROM information_schema.tables WHERE table_schema = CURRENT_SCHEMA() AND table_name = ? LIMIT 1',
                array($table)
            );
            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $row = $this->fetchOne(
                "SELECT 1 AS found FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
                array($table)
            );
            return $row !== null;
        }

        $row = $this->fetchOne(
            'SELECT 1 AS found FROM information_schema.tables WHERE table_name = ? LIMIT 1',
            array($table)
        );
        return $row !== null;
    }

    public function execute(string $sql, array $params = array()): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));

        return (int) $stmt->rowCount();
    }

    public function fetchOne(string $sql, array $params = array()): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function fetchAll(string $sql, array $params = array()): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->beginTransaction()) {
            throw new \RuntimeException('Failed to begin database transaction.');
        }
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool
    {
        $lockKey = self::normalizeLockName($name);
        $driver = $this->driverName();

        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute(array($lockKey, max(0, $timeoutSeconds)));
            $acquired = (int) $stmt->fetchColumn() === 1;
            if ($acquired) {
                $this->heldLocks[$lockKey] = true;
            }
            return $acquired;
        }

        if ($driver === 'pgsql') {
            $stmt = $this->pdo->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
            $stmt->execute(array($lockKey));
            $val = $stmt->fetchColumn();
            $acquired = $val === true || $val === 't' || $val === 1 || $val === '1';
            if ($acquired) {
                $this->heldLocks[$lockKey] = true;
            }
            return $acquired;
        }

        $this->heldLocks[$lockKey] = true;
        return true;
    }

    public function isNamedLockOwned(string $name): bool
    {
        $lockKey = self::normalizeLockName($name);
        $driver = $this->driverName();

        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            $stmt->execute(array($lockKey));
            return (int) $stmt->fetchColumn() === 1;
        }

        return !empty($this->heldLocks[$lockKey]);
    }

    public function releaseNamedLock(string $name): void
    {
        $lockKey = self::normalizeLockName($name);
        unset($this->heldLocks[$lockKey]);
        $driver = $this->driverName();

        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute(array($lockKey));
            return;
        }

        if ($driver === 'pgsql') {
            $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(hashtext(?))');
            $stmt->execute(array($lockKey));
        }
    }

    /**
     * Build conflict-safe INSERT SQL so duplicate keys never abort an active
     * PostgreSQL transaction (SQLSTATE 25P02).
     *
     * @param string $table
     * @param array<int, string> $colNames
     * @param string $driver
     * @param string|null $conflictColumn
     * @return string
     */
    public static function buildInsertUniqueSql(
        string $table,
        array $colNames,
        string $driver,
        ?string $conflictColumn = null
    ): string {
        self::assertIdentifier($table);
        if (count($colNames) === 0) {
            throw new \InvalidArgumentException('insertUnique requires at least one column.');
        }

        foreach ($colNames as $colName) {
            self::assertIdentifier($colName);
        }

        $placeholders = implode(', ', array_fill(0, count($colNames), '?'));
        $colsSql = implode(', ', $colNames);
        $normalizedDriver = strtolower(trim($driver));

        if ($normalizedDriver === 'mysql') {
            return 'INSERT IGNORE INTO ' . $table
                . ' (' . $colsSql . ')'
                . ' VALUES (' . $placeholders . ')';
        }

        if ($normalizedDriver === 'pgsql' || $normalizedDriver === 'sqlite') {
            $conflictClause = ' ON CONFLICT DO NOTHING';
            if ($conflictColumn !== null && trim($conflictColumn) !== '') {
                $conflictCol = trim($conflictColumn);
                self::assertIdentifier($conflictCol);
                $conflictClause = ' ON CONFLICT (' . $conflictCol . ') DO NOTHING';
            }

            return 'INSERT INTO ' . $table
                . ' (' . $colsSql . ')'
                . ' VALUES (' . $placeholders . ')'
                . $conflictClause;
        }

        return 'INSERT INTO ' . $table
            . ' (' . $colsSql . ')'
            . ' VALUES (' . $placeholders . ')';
    }

    public function insertUnique(string $table, array $columns, ?string $conflictColumn = null): bool
    {
        self::assertIdentifier($table);
        if (count($columns) === 0) {
            throw new \InvalidArgumentException('insertUnique requires at least one column.');
        }

        $colNames = array();
        $values = array();

        foreach ($columns as $col => $val) {
            $colStr = (string) $col;
            self::assertIdentifier($colStr);
            $colNames[] = $colStr;
            $values[] = $val;
        }

        $driver = $this->driverName();
        $sql = self::buildInsertUniqueSql($table, $colNames, $driver, $conflictColumn);

        if (in_array($driver, array('mysql', 'pgsql', 'sqlite'), true)) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
            return (int) $stmt->rowCount() === 1;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
            return true;
        } catch (\PDOException $e) {
            if (self::isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    public function casUpdate(string $table, array $set, array $where, array $conditions = array()): int
    {
        self::assertIdentifier($table);
        if (count($set) === 0) {
            throw new \InvalidArgumentException('casUpdate requires at least one column to update.');
        }
        if (count($where) === 0 && count($conditions) === 0) {
            throw new \InvalidArgumentException('casUpdate requires at least one WHERE predicate.');
        }

        $setClauses = array();
        $params = array();
        foreach ($set as $col => $val) {
            $colStr = (string) $col;
            self::assertIdentifier($colStr);
            $setClauses[] = $colStr . ' = ?';
            $params[] = $val;
        }

        $whereClauses = array();
        foreach ($where as $col => $val) {
            $colStr = (string) $col;
            self::assertIdentifier($colStr);
            if ($val === null) {
                $whereClauses[] = $colStr . ' IS NULL';
            } else {
                $whereClauses[] = $colStr . ' = ?';
                $params[] = $val;
            }
        }

        foreach ($conditions as $col => $spec) {
            $colStr = (string) $col;
            self::assertIdentifier($colStr);
            $op = isset($spec['op']) ? trim((string) $spec['op']) : '';
            if (!in_array($op, self::ALLOWED_OPERATORS, true)) {
                throw new \InvalidArgumentException('Unsupported comparison operator: ' . $op);
            }
            $whereClauses[] = $colStr . ' ' . $op . ' ?';
            $params[] = $spec['value'];
        }

        $sql = 'UPDATE ' . $table
            . ' SET ' . implode(', ', $setClauses)
            . ' WHERE ' . implode(' AND ', $whereClauses);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->rowCount();
    }

    public function deleteExpired(string $table, string $column, int $now, int $limit = 100): int
    {
        self::assertIdentifier($table);
        self::assertIdentifier($column);
        $safeLimit = max(1, $limit);
        $driver = $this->driverName();

        if ($driver === 'mysql') {
            $sql = 'DELETE FROM ' . $table . ' WHERE ' . $column . ' < ? ORDER BY ' . $column . ' ASC LIMIT ' . $safeLimit;
            return $this->execute($sql, array($now));
        }

        if ($driver === 'sqlite') {
            $sql = 'DELETE FROM ' . $table
                . ' WHERE rowid IN (SELECT rowid FROM ' . $table
                . ' WHERE ' . $column . ' < ? ORDER BY ' . $column . ' ASC LIMIT ' . $safeLimit . ')';
            return $this->execute($sql, array($now));
        }

        if ($driver === 'pgsql') {
            $sql = 'DELETE FROM ' . $table
                . ' WHERE ctid IN (SELECT ctid FROM ' . $table
                . ' WHERE ' . $column . ' < ? ORDER BY ' . $column . ' ASC LIMIT ' . $safeLimit . ')';
            return $this->execute($sql, array($now));
        }

        $sql = 'DELETE FROM ' . $table . ' WHERE ' . $column . ' < ?';
        return $this->execute($sql, array($now));
    }

    public static function isUniqueViolation(\PDOException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : array();
        $infoState = isset($errorInfo[0]) ? (string) $errorInfo[0] : '';
        $driverCode = isset($errorInfo[1]) ? (int) $errorInfo[1] : 0;

        if ($sqlState === '23505' || $infoState === '23505') {
            return true;
        }

        if ($sqlState === '23000' || $infoState === '23000') {
            if (in_array($driverCode, array(1062, 19, 1555, 2067), true)) {
                return true;
            }
            $message = strtolower($exception->getMessage());
            if ($driverCode === 0 && (strpos($message, 'unique') !== false || strpos($message, 'duplicate') !== false || strpos($message, 'primary') !== false)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeLockName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Lock name must be non-empty.');
        }

        if (strlen($trimmed) <= 64) {
            return $trimmed;
        }

        return 'pm_' . substr(hash('sha256', $trimmed), 0, 56);
    }

    private static function assertIdentifier(string $name): void
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL identifier: ' . $name);
        }
    }
}
