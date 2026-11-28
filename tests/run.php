<?php

declare(strict_types=1);

/**
 * Comprehensive standalone PHP 8.1+ conformance test suite for Paymos Blesta plugin.
 *
 * Covers Contracts 1-5 and every Task 1.1 acceptance scenario:
 * - CredentialStore (AesGcmEnvelope over Blesta.system_key, CredentialSet validation)
 * - ConnectHandler & AdminConnect (One-Click Connect, HTTPS/binding checks, auth+CSRF, 0 manual secrets)
 * - CheckoutService (STATUS_CREATING, crash recovery, products[], InvoiceReplacement, paid_late_noted, RFC 9457)
 * - CallbackProcessor (MultiEnvironmentWebhookVerifier, dual-v1=, 401/409/200/400, withdrawal.*, reverse-verify, AmountGuard, rollback, paid_late)
 * - Reconciler & PaymosPlugin companion (24h sweep, 600s throttle, cron())
 * - RefundIntentStore & RefundAdapter & AdminRefunds (full + overpayment, nativeRefund() async-safe null, applyCompleted(), RefundDiscovery, rejects)
 * - 6 native locales parity, ISO 4217 config.json, zero-leak docs, and php -l syntax lint
 */

// ── Blesta Runtime Stubs ────────────────────────────────────────────────────

if (!class_exists('Configure', false)) {
    final class Configure
    {
        /** @var array<string, mixed> */
        private static $values = array(
            'Blesta.gw_callback_url' => 'https://billing.example.com/callback/gw/',
            'Blesta.company_id' => '1',
            'Blesta.system_key' => 'blesta-test-system-key-32-bytes-minimum-entropy-0123456789',
            'Blesta.language' => 'en_us',
            'Route.admin' => 'admin',
        );

        public static function set(string $key, $value): void
        {
            self::$values[$key] = $value;
        }

        /** @return mixed */
        public static function get(string $key)
        {
            return self::$values[$key] ?? null;
        }
    }
}

if (!class_exists('Language', false)) {
    final class Language
    {
        /** @var array<string, string> */
        private static $terms = array();

        public static function loadLang(string $file, ?string $lang = null, ?string $dir = null): void
        {
            $locale = $lang ?: 'en_us';
            $baseDir = $dir ?: '';
            $path = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $file . '.php';
            if (is_file($path)) {
                $lang = array();
                include $path;
                if (is_array($lang)) {
                    foreach ($lang as $key => $value) {
                        self::$terms[(string) $key] = (string) $value;
                    }
                }
            }
        }

        public static function _(string $key, bool $return = false): string
        {
            $value = self::$terms[$key] ?? $key;
            if (!$return) {
                echo $value;
            }
            return $value;
        }
    }
}

if (!class_exists('Input', false)) {
    final class Input
    {
        /** @var array<string, mixed> */
        private $errors = array();

        /** @param array<string, mixed> $errors */
        public function setErrors(array $errors): void
        {
            $this->errors = $errors;
        }

        /** @return array<string, mixed> */
        public function errors(): array
        {
            return $this->errors;
        }
    }
}

if (!class_exists('Html', false)) {
    final class Html
    {
        public function safe(?string $value): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }
    }
}

if (!class_exists('Form', false)) {
    final class Form
    {
        /** @var string */
        public static $csrfToken = 'valid-blesta-csrf-token-12345';

        public static function getCsrfToken(): string
        {
            return self::$csrfToken;
        }

        public static function verifyCsrfToken(string $token): bool
        {
            return hash_equals(self::$csrfToken, $token);
        }

        private static function attrs(?array $attributes): string
        {
            if (!is_array($attributes) || count($attributes) === 0) {
                return '';
            }
            $parts = array();
            foreach ($attributes as $k => $v) {
                $parts[] = htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8')
                    . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
            }
            return ' ' . implode(' ', $parts);
        }

        public function label(string $value, ?string $for = null, ?array $attributes = null): void
        {
            $attrs = is_array($attributes) ? $attributes : array();
            if ($for !== null) {
                $attrs['for'] = $for;
            }
            echo '<label' . self::attrs($attrs) . '>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</label>';
        }

        public function fieldSelect(string $name, array $options = array(), $selected = null, ?array $attributes = null): void
        {
            $attrs = is_array($attributes) ? $attributes : array();
            $attrs['name'] = $name;
            echo '<select' . self::attrs($attrs) . '>';
            foreach ($options as $val => $label) {
                $sel = ((string) $val === (string) $selected) ? ' selected="selected"' : '';
                echo '<option value="' . htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                    . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            echo '</select>';
        }

        public function fieldText(string $name, $value = null, ?array $attributes = null): void
        {
            $attrs = is_array($attributes) ? $attributes : array();
            $attrs['type'] = 'text';
            $attrs['name'] = $name;
            if ($value !== null) {
                $attrs['value'] = (string) $value;
            }
            echo '<input' . self::attrs($attrs) . ' />';
        }
    }
}

if (!class_exists('View', false)) {
    final class View
    {
        /** @var string */
        private $file;
        /** @var string */
        private $dir;
        /** @var array<string, mixed> */
        private $vars = array();
        /** @var Form|null */
        public $Form;
        /** @var Html|null */
        public $Html;

        public function __construct(string $file, string $dir)
        {
            $this->file = $file;
            $this->dir = $dir;
        }

        public function set(string $key, $value): void
        {
            $this->vars[$key] = $value;
        }

        public function _(string $key, bool $return = false): string
        {
            return Language::_($key, $return);
        }

        public function fetch(): string
        {
            $path = rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR . $this->file . '.pdt';
            if (!is_file($path)) {
                throw new \RuntimeException('View template not found: ' . $path);
            }
            extract($this->vars, EXTR_SKIP);
            ob_start();
            include $path;
            return (string) ob_get_clean();
        }
    }
}

if (!class_exists('Loader', false)) {
    final class Loader
    {
        /** @var callable|null */
        public static $recordFactory;
        /** @var array<string, object> */
        public static $models = array();

        public static function loadComponents(object $parent, array $components): void
        {
            foreach ($components as $component) {
                if ($component === 'Input') {
                    $parent->Input = new Input();
                } elseif ($component === 'Record' && self::$recordFactory !== null) {
                    $parent->Record = call_user_func(self::$recordFactory);
                }
            }
        }

        public static function loadHelpers(object $parent, array $helpers): void
        {
            foreach ($helpers as $helper) {
                if ($helper === 'Form') {
                    $parent->Form = new Form();
                } elseif ($helper === 'Html') {
                    $parent->Html = new Html();
                }
            }
        }

        public static function loadModels(object $parent, array $models): void
        {
            foreach ($models as $model) {
                if (isset(self::$models[$model])) {
                    $parent->{$model} = self::$models[$model];
                }
            }
        }
    }
}

if (!class_exists('NonmerchantGateway', false)) {
    abstract class NonmerchantGateway
    {
        /** @var Input */
        public $Input;
        /** @var object|null */
        public $Record;
        /** @var object|null */
        public $Invoices;
        /** @var View|null */
        protected $view;
        /** @var array<string, mixed> */
        private $config = array();
        /** @var array<int, array<string, mixed>> */
        public $gatewayLogs = array();

        protected function loadConfig(string $file): void
        {
            $decoded = json_decode((string) file_get_contents($file), true);
            $this->config = is_array($decoded) ? $decoded : array();
        }

        public function getVersion(): string
        {
            return (string) ($this->config['version'] ?? '');
        }

        /** @return array<int, string> */
        public function getCurrencies(): array
        {
            return is_array($this->config['currencies'] ?? null) ? $this->config['currencies'] : array();
        }

        protected function makeView(string $file, string $viewDir, string $path): View
        {
            $dir = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $viewDir;
            return new View($file, $dir);
        }

        /** @return array<string, mixed>|false */
        public function errors()
        {
            $errs = $this->Input->errors();
            return count($errs) > 0 ? $errs : false;
        }

        protected function log(string $url, string $data, string $direction, bool $success): void
        {
            if (!in_array($direction, array('input', 'output'), true)) {
                throw new \InvalidArgumentException('Invalid log direction: ' . $direction);
            }
            $this->gatewayLogs[] = array(
                'url' => $url,
                'data' => $data,
                'direction' => $direction,
                'success' => $success,
            );
        }
    }
}

if (!class_exists('Plugin', false)) {
    abstract class Plugin
    {
        /** @var array<string, mixed> */
        private $config = array();

        protected function loadConfig(string $file): void
        {
            $decoded = json_decode((string) file_get_contents($file), true);
            $this->config = is_array($decoded) ? $decoded : array();
        }

        public function getVersion(): string
        {
            return (string) ($this->config['version'] ?? '');
        }
    }
}

if (!class_exists('AppController', false)) {
    abstract class AppController
    {
        /** @var object|null */
        public $Session;

        /** @var object|null */
        public $Form;

        /** @var array<int|string, mixed> */
        public $get = array();

        /** @var object|null */
        public $view;

        /** @var object|null */
        public $structure;

        /** @var string|null */
        public $plugin;

        /** @var string|null */
        public $controller;

        /** @var string|null */
        public $base_uri;

        /** @var string|null */
        public $action;

        /** @var int */
        public $requireLoginCalls = 0;

        /**
         * ACO aliases (plugin.controller) the logged-in staff group is NOT allowed to reach.
         *
         * @var array<int, string>
         */
        public static $deniedAcos = array();

        /**
         * Optional ACL emulation: function (string $aco): bool — true when the staff group may enter.
         *
         * @var callable|null
         */
        public static $aclCheck = null;

        public function preAction(): void
        {
        }

        /**
         * Blesta AppController::requireLogin(): staff login + ACL on plugin.controller.
         */
        public function requireLogin($redirect_to = null): void
        {
            $this->requireLoginCalls++;
            $aco = ($this->plugin ? $this->plugin . '.' : '') . (string) $this->controller;
            if (in_array($aco, self::$deniedAcos, true)
                || (self::$aclCheck !== null && call_user_func(self::$aclCheck, $aco) !== true)) {
                throw new \RuntimeException('blesta_acl_denied:' . $aco);
            }
        }
    }
}

// ── Load Gateway & Companion Plugin ─────────────────────────────────────────

$pluginRoot = dirname(__DIR__);
$gatewayRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'gateways'
    . DIRECTORY_SEPARATOR . 'nonmerchant' . DIRECTORY_SEPARATOR . 'paymos';
$companionRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'paymos';

require_once $gatewayRoot . DIRECTORY_SEPARATOR . 'paymos.php';
require_once $companionRoot . DIRECTORY_SEPARATOR . 'paymos_plugin.php';
require_once $companionRoot . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'admin_connect.php';
require_once $companionRoot . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'admin_refunds.php';

use Paymos\Client;
use Paymos\Connect\DeviceConnectClient;
use Paymos\Exception\EventInProgressException;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\RefundCandidateStoreInterface;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundStates;
use PaymosBlesta\CallbackProcessor;
use PaymosBlesta\CheckoutService;
use PaymosBlesta\Config;
use PaymosBlesta\ConnectHandler;
use PaymosBlesta\CredentialStore;
use PaymosBlesta\DbDriverInterface;
use PaymosBlesta\PdoEventStore;
use PaymosBlesta\Reconciler;
use PaymosBlesta\RefundAdapter;
use PaymosBlesta\RefundIntentStore;
use PaymosBlesta\SnapshotStore;
use PaymosBlesta\StaffPermissions;
use PaymosBlesta\Translation;

// ── InMemoryDbDriver (Full SQL + Transactions + Advisory Locks) ─────────────

final class InMemoryDbDriver implements DbDriverInterface
{
    /** @var string */
    public $driver = 'mysql';

    /** @var bool */
    public $activeTransaction = false;

    /** @var array<string, mixed>|null */
    private $txBackup;

    /** @var array<string, bool> */
    public $existingTables = array(
        CredentialStore::TABLE => true,
        PdoEventStore::TABLE => true,
        SnapshotStore::TABLE => true,
        RefundIntentStore::TABLE => true,
        RefundIntentStore::EXTERNAL_TABLE => true,
    );

    /** @var array<string, array<string, array<string, mixed>>> */
    public $tables = array(
        CredentialStore::TABLE => array(),
        PdoEventStore::TABLE => array(),
        SnapshotStore::TABLE => array(),
        RefundIntentStore::TABLE => array(),
        RefundIntentStore::EXTERNAL_TABLE => array(),
    );

    /** @var array<string, string> */
    private $primaryKeys = array(
        CredentialStore::TABLE => 'setting_key',
        PdoEventStore::TABLE => 'event_id',
        SnapshotStore::TABLE => 'external_order_id',
        RefundIntentStore::TABLE => 'operation_id',
        RefundIntentStore::EXTERNAL_TABLE => 'withdrawal_id',
    );

    /** @var array<string, bool> */
    public $locks = array();

    /** @var array<string, bool> */
    public $externallyHeldLocks = array();

    public function driverName(): string
    {
        return $this->driver;
    }

    public function inTransaction(): bool
    {
        return $this->activeTransaction;
    }

    public function tableExists(string $table): bool
    {
        return !empty($this->existingTables[$table]);
    }

    public function beginTransaction(): void
    {
        $this->txBackup = $this->tables;
        $this->activeTransaction = true;
    }

    public function commit(): bool
    {
        $this->txBackup = null;
        $this->activeTransaction = false;
        return true;
    }

    public function rollBack(): void
    {
        if ($this->txBackup !== null) {
            $this->tables = $this->txBackup;
            $this->txBackup = null;
        }
        $this->activeTransaction = false;
    }

    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool
    {
        if (!empty($this->externallyHeldLocks[$name])) {
            return false;
        }
        $this->locks[$name] = true;
        return true;
    }

    public function isNamedLockOwned(string $name): bool
    {
        return !empty($this->locks[$name]) && empty($this->externallyHeldLocks[$name]);
    }

    public function releaseNamedLock(string $name): void
    {
        unset($this->locks[$name]);
    }

    public function execute(string $sql, array $params = array()): int
    {
        if (stripos($sql, 'CREATE TABLE') === 0) {
            if (preg_match('/CREATE TABLE IF NOT EXISTS\s+([a-zA-Z0-9_]+)/i', $sql, $m) === 1) {
                $this->existingTables[$m[1]] = true;
                if (!isset($this->tables[$m[1]])) {
                    $this->tables[$m[1]] = array();
                }
            }
            return 0;
        }

        if (preg_match('/^DELETE FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+(.+)$/is', trim($sql), $m) === 1) {
            $table = $m[1];
            $where = trim($m[2]);
            $rows = $this->tables[$table] ?? array();
            $deleted = 0;
            foreach ($rows as $pk => $row) {
                if ($this->matchesWhere($row, $where, $params)) {
                    unset($this->tables[$table][$pk]);
                    $deleted++;
                }
            }
            return $deleted;
        }

        return 0;
    }

    public function fetchOne(string $sql, array $params = array()): ?array
    {
        $rows = $this->fetchAll($sql, $params);
        return count($rows) > 0 ? $rows[0] : null;
    }

    public function fetchAll(string $sql, array $params = array()): array
    {
        if (preg_match('/^SELECT\s+.+?\s+FROM\s+([a-zA-Z0-9_]+)(?:\s+WHERE\s+(.+?))?(?:\s+ORDER BY\s+(.+?))?(?:\s+LIMIT\s+(\d+))?\s*$/is', trim($sql), $m) !== 1) {
            return array();
        }

        $table = $m[1];
        $where = isset($m[2]) ? trim($m[2]) : '';
        $orderBy = isset($m[3]) ? trim($m[3]) : '';
        $limit = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;

        $matched = array();
        foreach ($this->tables[$table] ?? array() as $row) {
            if ($where === '' || $this->matchesWhere($row, $where, $params)) {
                $matched[] = $row;
            }
        }

        if ($orderBy !== '') {
            $specs = array_map('trim', explode(',', $orderBy));
            usort($matched, static function (array $a, array $b) use ($specs): int {
                foreach ($specs as $spec) {
                    $parts = preg_split('/\s+/', $spec);
                    $col = $parts[0];
                    $dir = isset($parts[1]) && strtoupper($parts[1]) === 'DESC' ? -1 : 1;
                    $va = $a[$col] ?? null;
                    $vb = $b[$col] ?? null;
                    if ($va == $vb) {
                        continue;
                    }
                    return ($va < $vb ? -1 : 1) * $dir;
                }
                return 0;
            });
        }

        if ($limit !== null) {
            $matched = array_slice($matched, 0, $limit);
        }

        return $matched;
    }

    public function insertUnique(string $table, array $columns, ?string $conflictColumn = null): bool
    {
        $pkCol = $this->primaryKeys[$table] ?? $conflictColumn ?? array_key_first($columns);
        $pk = (string) ($columns[$pkCol] ?? '');
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = array();
        }
        if (isset($this->tables[$table][$pk])) {
            return false;
        }

        // Enforce unique constraints on paymos_refund_intents
        if ($table === RefundIntentStore::TABLE) {
            foreach ($this->tables[$table] as $existing) {
                if ($existing['installation_id'] === $columns['installation_id']
                    && $existing['request_token'] === $columns['request_token']) {
                    return false;
                }
                if ($existing['external_order_id'] === $columns['external_order_id']) {
                    return false;
                }
                if ($columns['withdrawal_id'] !== null && $existing['withdrawal_id'] === $columns['withdrawal_id']) {
                    return false;
                }
            }
        }

        $this->tables[$table][$pk] = $columns;
        return true;
    }

    public function casUpdate(string $table, array $set, array $where, array $conditions = array()): int
    {
        $affected = 0;
        foreach ($this->tables[$table] ?? array() as $pk => $row) {
            $ok = true;
            foreach ($where as $col => $val) {
                if ($val === null) {
                    if (($row[$col] ?? null) !== null) {
                        $ok = false;
                        break;
                    }
                } elseif (!array_key_exists($col, $row) || (string) $row[$col] !== (string) $val) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            foreach ($conditions as $col => $spec) {
                $val = $row[$col] ?? null;
                $cmp = $spec['value'];
                $op = $spec['op'];
                $pass = match ($op) {
                    '=' => $val == $cmp,
                    '!=' , '<>' => $val != $cmp,
                    '<' => $val < $cmp,
                    '<=' => $val <= $cmp,
                    '>' => $val > $cmp,
                    '>=' => $val >= $cmp,
                    default => false,
                };
                if (!$pass) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            foreach ($set as $col => $val) {
                $row[$col] = $val;
            }
            $this->tables[$table][$pk] = $row;
            $affected++;
        }

        return $affected;
    }

    public function deleteExpired(string $table, string $column, int $now, int $limit = 100): int
    {
        $deleted = 0;
        foreach ($this->tables[$table] ?? array() as $pk => $row) {
            if ($deleted >= $limit) {
                break;
            }
            if (isset($row[$column]) && (int) $row[$column] < $now) {
                unset($this->tables[$table][$pk]);
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, mixed> $params
     */
    private function matchesWhere(array $row, string $where, array $params): bool
    {
        $clauses = preg_split('/\s+AND\s+/i', $where);
        $pIdx = 0;
        foreach ($clauses as $clause) {
            $clause = trim($clause);
            if (preg_match('/^([a-zA-Z0-9_]+)\s+NOT\s+IN\s*\((.+)\)$/i', $clause, $m) === 1) {
                $vals = array_map(static function (string $v): string {
                    return trim($v, " '\"");
                }, explode(',', $m[2]));
                if (in_array((string) ($row[$m[1]] ?? ''), $vals, true)) {
                    return false;
                }
                continue;
            }
            if (preg_match('/^([a-zA-Z0-9_]+)\s+IN\s*\((.+)\)$/i', $clause, $m) === 1) {
                $vals = array_map(static function (string $v): string {
                    return trim($v, " '\"");
                }, explode(',', $m[2]));
                if (!in_array((string) ($row[$m[1]] ?? ''), $vals, true)) {
                    return false;
                }
                continue;
            }
            if (preg_match('/^([a-zA-Z0-9_]+)\s+IS\s+NULL$/i', $clause, $m) === 1) {
                if (($row[$m[1]] ?? null) !== null) {
                    return false;
                }
                continue;
            }
            if (preg_match('/^([a-zA-Z0-9_]+)\s*(=|!=|<>|<=|>=|<|>)\s*\?$/', $clause, $m) === 1) {
                $col = $m[1];
                $op = $m[2];
                $expected = $params[$pIdx++] ?? null;
                $actual = $row[$col] ?? null;
                if ($actual === null) {
                    return false;
                }
                $ok = match ($op) {
                    '=' => (string) $actual === (string) $expected,
                    '!=', '<>' => (string) $actual !== (string) $expected,
                    '<' => $actual < $expected,
                    '<=' => $actual <= $expected,
                    '>' => is_numeric($actual) && is_numeric($expected) ? $actual > $expected : strcmp((string) $actual, (string) $expected) > 0,
                    '>=' => $actual >= $expected,
                    default => false,
                };
                if (!$ok) {
                    return false;
                }
            }
        }
        return true;
    }
}

// ── Test Harness & Helpers ──────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function runTest(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "[PASS] {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "[FAIL] {$name}: {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n";
    }
}

function assertTrue(bool $cond, string $msg = 'Assertion failed'): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $exp = var_export($expected, true);
        $act = var_export($actual, true);
        throw new \RuntimeException(($msg !== '' ? $msg . ': ' : '') . "Expected {$exp}, got {$act}");
    }
}

function assertThrows(string $exceptionClass, callable $fn, ?string $messageSubstring = null): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $exceptionClass) {
            throw new \RuntimeException('Expected ' . $exceptionClass . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }
        if ($messageSubstring !== null && strpos($e->getMessage(), $messageSubstring) === false) {
            throw new \RuntimeException("Expected exception message containing '{$messageSubstring}', got '{$e->getMessage()}'");
        }
        return $e;
    }
    throw new \RuntimeException('Expected exception ' . $exceptionClass . ' was not thrown.');
}

function sampleCredentials(bool $refundsEnabled = true, bool $includeRefundCapability = true): array
{
    $base = array(
        'sandbox' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_test_blesta_sandbox_123456',
            'api_secret' => 'sk_test_blesta_sandbox_secret_987654',
            'project_id' => 'prj_sandbox_blesta_001',
            'webhook_secret' => 'whsec_sandbox_blesta_secret_001',
        ),
        'live' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_live_blesta_live_123456',
            'api_secret' => 'sk_live_blesta_live_secret_987654',
            'project_id' => 'prj_live_blesta_001',
            'webhook_secret' => 'whsec_live_blesta_secret_001',
        ),
    );
    if ($includeRefundCapability) {
        foreach (array('sandbox', 'live') as $env) {
            $base[$env]['capabilities'] = array('invoice_refunds_v1');
            $base[$env]['refund_contract_version'] = 1;
            $base[$env]['refunds_enabled'] = $refundsEnabled;
        }
    }
    return $base;
}

function signWebhook(string $rawBody, string $secret, int $timestamp, ?string $extraSecret = null): string
{
    $sig1 = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    $header = 't=' . $timestamp . ',v1=' . $sig1;
    if ($extraSecret !== null) {
        $sig2 = hash_hmac('sha256', $timestamp . '.' . $rawBody, $extraSecret);
        $header .= ',v1=' . $sig2;
    }
    return $header;
}

function jsonResponse(int $status, array $payload, array $headers = array('Content-Type' => 'application/json')): HttpResponse
{
    return new HttpResponse($status, (string) json_encode($payload), $headers);
}

function makeGateway(InMemoryDbDriver $db, ?TransportInterface $transport = null, ?callable $clock = null, string $mode = 'sandbox'): Paymos
{
    $gw = new Paymos();
    $gw->setDbDriver($db);
    if ($transport !== null) {
        $gw->setTransport($transport);
    }
    if ($clock !== null) {
        $gw->setClock($clock);
    }
    $gw->setMeta(array('mode' => $mode, 'button_text' => 'Pay with Paymos'));
    $gw->setCurrency('USD');
    return $gw;
}

final class SpyTransport implements TransportInterface
{
    /** @var callable */
    private $handler;
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public $requests = array();

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function request($method, $url, array $headers = array(), $body = null, $timeoutSeconds = 30)
    {
        $entry = array(
            'method' => (string) $method,
            'url' => (string) $url,
            'headers' => $headers,
            'body' => $body !== null ? (string) $body : null,
        );
        $this->requests[] = $entry;
        return call_user_func($this->handler, $entry, count($this->requests) - 1);
    }
}

// ── Group 1: CredentialStore & AesGcmEnvelope ───────────────────────────────

runTest('1.1 CredentialStore seals/opens Sandbox+Live via AesGcmEnvelope over Blesta.system_key', function () {
    $db = new InMemoryDbDriver();
    $now = 1764000000;
    $store = new CredentialStore($db, null, static fn (): int => $now);
    $creds = sampleCredentials();
    $store->saveCredentials($creds);

    $rawRow = $db->tables[CredentialStore::TABLE][CredentialStore::CREDENTIALS_KEY]['setting_value'];
    assertTrue(strpos($rawRow, 'sk_live_') === false && strpos($rawRow, 'whsec_') === false, 'Raw DB row must never store plaintext secrets.');
    assertTrue(strpos($rawRow, 'A256GCM') !== false, 'Envelope must use AES-256-GCM.');

    $loaded = $store->loadCredentials();
    assertSame('prj_sandbox_blesta_001', $loaded['sandbox']['project_id']);
    assertSame('prj_live_blesta_001', $loaded['live']['project_id']);

    $rc = $store->refundConfig('live');
    assertSame('prj_live_blesta_001', $rc['project_id']);
    assertSame(array('invoice_refunds_v1'), $rc['capabilities']);
    assertSame(1, $rc['refund_contract_version']);
    assertSame(true, $rc['refunds_enabled']);
});

runTest('1.2 CredentialStore fails closed on missing Blesta.system_key, tampered envelope, bad prj_/base_url', function () {
    $db = new InMemoryDbDriver();
    $noKeyStore = new CredentialStore($db, static fn (): string => '');
    assertThrows(\RuntimeException::class, static fn () => $noKeyStore->saveCredentials(sampleCredentials()), 'Blesta.system_key');

    $store = new CredentialStore($db);
    $badPrj = sampleCredentials();
    $badPrj['live']['project_id'] = 'invalid_no_prefix';
    assertThrows(\InvalidArgumentException::class, static fn () => $store->saveCredentials($badPrj), 'project ID');

    $badUrl = sampleCredentials();
    $badUrl['live']['base_url'] = 'https://evil.example.com';
    assertThrows(\InvalidArgumentException::class, static fn () => $store->saveCredentials($badUrl), 'not trusted');

    $store->saveCredentials(sampleCredentials());
    $db->tables[CredentialStore::TABLE][CredentialStore::CREDENTIALS_KEY]['setting_value'] = '{"version":1,"algorithm":"A256GCM","iv":"AAAAAAAAAAAAAAAA","tag":"AAAAAAAAAAAAAAAAAAAAAA==","ciphertext":"AAAA"}';
    assertThrows(\RuntimeException::class, static fn () => $store->loadCredentials());
});

runTest('1.3 CredentialStore device state expires automatically', function () {
    $db = new InMemoryDbDriver();
    $now = 1764000000;
    $store = new CredentialStore($db, null, static function () use (&$now): int {
        return $now;
    });
    $store->saveState(array('device_code' => 'dev_secret_123', 'expires_in' => 60, 'cms' => 'blesta'));
    $raw = $db->tables[CredentialStore::TABLE][CredentialStore::STATE_KEY]['setting_value'];
    assertTrue(strpos($raw, 'dev_secret_123') === false, 'Device code must be encrypted.');
    assertSame('dev_secret_123', $store->loadState()['device_code']);

    $now += 61;
    assertSame(array(), $store->loadState());
    assertTrue(!isset($db->tables[CredentialStore::TABLE][CredentialStore::STATE_KEY]), 'Expired state must be cleared.');
});

// ── Group 2: Connect Paymos & Settings HTML (Zero Manual Secrets) ───────────

runTest('2.1 One-Click Connect provisions Sandbox+Live atomically and clears state', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $transport = new MockTransport(array(
        jsonResponse(200, array(
            'device_code' => 'dev_blesta_abc',
            'user_code' => 'ABCD-1234',
            'verification_uri_complete' => 'https://app.paymos.io/connect/approve?user_code=ABCD-1234',
            'expires_in' => 600,
            'interval' => 5,
        )),
        jsonResponse(400, array('error' => 'authorization_pending')),
        jsonResponse(200, array(
            'paymos_config' => array(
                'plugin' => 'blesta',
                'source_url' => 'https://billing.example.com',
                'environments' => sampleCredentials(),
            ),
        )),
    ));

    $handler = new ConnectHandler($store, new DeviceConnectClient('https://app.paymos.io', $transport));
    $started = $handler->start('https://billing.example.com/', 'https://billing.example.com/admin/settings/company/gateways/manage/1/');
    assertSame('ABCD-1234', $started['user_code']);
    assertTrue(!array_key_exists('device_code', $started), 'start() must never expose device_code.');
    assertTrue(strpos($started['verification_url'], 'cms=blesta') !== false, 'Verification URL must carry cms=blesta.');

    $pending = $handler->poll('https://billing.example.com');
    assertSame('authorization_pending', $pending['status']);

    $done = $handler->poll('https://billing.example.com');
    assertSame('connected', $done['status']);
    assertSame(array(), $store->loadState(), 'Device state must be cleared after connect.');
    assertSame('prj_live_blesta_001', $store->loadCredentials()['live']['project_id']);
});

runTest('2.2 Connect rejects HTTP store URL and mismatched plugin or source_url', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $httpSpy = new SpyTransport(static function (): HttpResponse {
        throw new \RuntimeException('HTTP must never be called for non-HTTPS source_url');
    });
    $handler = new ConnectHandler($store, new DeviceConnectClient('https://app.paymos.io', $httpSpy));
    assertThrows(\InvalidArgumentException::class, static fn () => $handler->start('http://billing.example.com'), 'HTTPS');
    assertSame(0, count($httpSpy->requests));

    // Mismatched plugin slug
    $mismatchTransport = new MockTransport(array(
        jsonResponse(200, array(
            'device_code' => 'dev_mismatch',
            'user_code' => 'ZZZZ-9999',
            'verification_uri_complete' => 'https://app.paymos.io/connect/approve?user_code=ZZZZ-9999',
            'expires_in' => 600,
            'interval' => 5,
        )),
        jsonResponse(200, array(
            'paymos_config' => array(
                'plugin' => 'whmcs',
                'source_url' => 'https://billing.example.com',
                'environments' => sampleCredentials(),
            ),
        )),
    ));
    $handler2 = new ConnectHandler($store, new DeviceConnectClient('https://app.paymos.io', $mismatchTransport));
    $handler2->start('https://billing.example.com');
    assertThrows(\RuntimeException::class, static fn () => $handler2->poll('https://billing.example.com'), 'does not match');
    assertSame(array(), $store->loadCredentials(), 'Mismatched plugin must NOT store credentials.');
    assertSame(array(), $store->loadState(), 'State must be cleared on mismatch.');
});

runTest('2.3 AdminConnect enforces POST, staff auth, and CSRF token', function () {
    $db = new InMemoryDbDriver();
    $controller = new AdminConnect();
    $controller->setDbDriver($db);
    $controller->setStaffAuthenticator(static fn (): bool => false);
    $controller->setCsrfVerifier(static fn (string $t): bool => $t === 'tok_ok');

    $getRes = $controller->handleRequest('GET', array());
    assertSame(405, $getRes['http_status']);

    $unauthRes = $controller->handleRequest('POST', array('action' => 'start', '_csrf_token' => 'tok_ok'));
    assertSame(403, $unauthRes['http_status']);

    $controller->setStaffAuthenticator(static fn (): bool => true);
    $badCsrfRes = $controller->handleRequest('POST', array('action' => 'start', '_csrf_token' => 'wrong'));
    assertSame(403, $badCsrfRes['http_status']);
});

runTest('2.4 Settings HTML renders Connect Paymos + mode + button_text with ZERO secret inputs', function () {
    $db = new InMemoryDbDriver();
    (new CredentialStore($db))->saveCredentials(sampleCredentials());
    $gw = makeGateway($db);

    $html = $gw->getSettings(array('mode' => 'live', 'button_text' => 'Pay Crypto'));
    assertTrue(strpos($html, 'paymos-connect-button') !== false, 'Connect Paymos button must be rendered.');
    assertTrue(strpos($html, 'name="mode"') !== false, 'Mode selector must be rendered.');
    assertTrue(strpos($html, 'name="button_text"') !== false, 'Button text input must be rendered.');
    assertTrue(strpos($html, '/callback/gw/1/paymos/') !== false, 'Canonical company 1 callback URL must be shown.');
    foreach (array('type="password"', 'name="api_key"', 'name="api_secret"', 'name="project_id"', 'name="webhook_secret"', 'name="base_url"', 'sk_live_', 'sk_test_', 'whsec_') as $forbidden) {
        assertTrue(stripos($html, $forbidden) === false, "Settings HTML must NOT contain {$forbidden}");
    }

    // editSettings strips legacy secret keys
    $saved = $gw->editSettings(array(
        'mode' => 'live',
        'button_text' => 'Pay Now',
        'api_key' => 'pk_live_hack',
        'api_secret' => 'sk_live_hack',
        'webhook_secret' => 'whsec_hack',
        'project_id' => 'prj_hack',
        'base_url' => 'https://evil.example',
    ));
    assertSame(array('mode' => 'live', 'button_text' => 'Pay Now'), $saved);
    assertSame(array(), $gw->encryptableFields());
});

// ── Group 3: CheckoutService ────────────────────────────────────────────────

runTest('3.1 Checkout persists STATUS_CREATING before POST /v1/invoices with products[] and no merchant_id/TTL', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $snapshots = new SnapshotStore($db);

    $observedStatusBeforeHttp = null;
    $sentBody = null;
    $transport = new SpyTransport(function (array $req) use ($db, &$observedStatusBeforeHttp, &$sentBody): HttpResponse {
        $rows = $db->tables[SnapshotStore::TABLE];
        assertTrue(count($rows) >= 1, 'STATUS_CREATING row must already exist in DB BEFORE POST.');
        $sentBody = json_decode((string) $req['body'], true);
        $extId = $sentBody['external_order_id'] ?? '';
        assertTrue(isset($rows[$extId]), 'Matching STATUS_CREATING row must exist before POST.');
        $observedStatusBeforeHttp = $rows[$extId]['status'];

        return jsonResponse(200, array(
            'invoice_id' => 'inv_paymos_' . count($rows),
            'payment_url' => 'https://checkout.paymos.io/inv/inv_paymos_' . count($rows),
            'status' => 'awaiting_client',
            'expires_at' => 1764003600,
        ));
    });

    $checkout = new CheckoutService(
        $config,
        $snapshots,
        static fn (Config $c, string $env): Client => new Client($c->clientConfig($env), $transport)
    );

    $res = $checkout->createCheckout(
        '77',
        '49.90',
        'USD',
        array(array('id' => 101, 'amount' => '49.90')),
        array(
            'line_items' => array(
                array('description' => "VPS Plan Pro\n4 vCPU / 8 GB RAM", 'qty' => '1', 'amount' => '39.90'),
                array('description' => 'IPv4 Add-on', 'qty' => 2, 'amount' => '5.00'),
            ),
        )
    );

    assertSame(CheckoutService::STATUS_CREATING, $observedStatusBeforeHttp);
    assertSame('inv_paymos_1', $res['paymos_invoice_id']);
    assertSame('49.9', $sentBody['amount'], 'Amount must be normalized decimal string without float rounding.');
    assertSame('USD', $sentBody['currency']);
    assertSame('prj_sandbox_blesta_001', $sentBody['project_id']);
    assertSame('77', $sentBody['client_id']);
    assertTrue(!isset($sentBody['merchant_id']) && !isset($sentBody['ttl']) && !isset($sentBody['lifetime']) && !isset($sentBody['expires_in']), 'Payload must omit merchant_id and TTL.');
    assertSame(2, count($sentBody['products']));
    assertSame('VPS Plan Pro', $sentBody['products'][0]['title']);
    assertSame('4 vCPU / 8 GB RAM', $sentBody['products'][0]['description']);
    assertSame(1, $sentBody['products'][0]['quantity']);
    assertSame('39.9', $sentBody['products'][0]['unit_amount']);
    assertSame(2, $sentBody['products'][1]['quantity']);
    assertSame('5', $sentBody['products'][1]['unit_amount']);

    // Email as client_id must never be forwarded to Paymos
    $res2 = $checkout->createCheckout('buyer@example.com', '10.00', 'USD', array(array('id' => 102, 'amount' => '10.00')));
    assertTrue(!isset($sentBody['client_id']), 'Email must never be sent as client_id.');
    assertTrue(!isset($sentBody['products']), 'Empty line items must fall back to amount-only.');
});

runTest('3.2 Checkout recoverPendingIntent() replays exact external_order_id/amount/currency/env without duplicate', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $snapshots = new SnapshotStore($db);

    $postCalls = array();
    $transport = new SpyTransport(function (array $req, int $idx) use (&$postCalls): HttpResponse {
        if ($req['method'] === 'POST') {
            $postCalls[] = json_decode((string) $req['body'], true);
            if (count($postCalls) === 1) {
                throw new \RuntimeException('Simulated process crash after HTTP dispatch');
            }
            return jsonResponse(200, array(
                'invoice_id' => 'inv_recovered_200',
                'payment_url' => 'https://checkout.paymos.io/inv/inv_recovered_200',
                'status' => 'awaiting_client',
            ));
        }
        return jsonResponse(200, array(
            'invoice_id' => 'inv_recovered_200',
            'status' => 'awaiting_client',
        ));
    });

    $checkout = new CheckoutService(
        $config,
        $snapshots,
        static fn (Config $c, string $env): Client => new Client($c->clientConfig($env), $transport)
    );

    assertThrows(\RuntimeException::class, static fn () => $checkout->createCheckout('88', '25.00', 'USD', array(array('id' => 200, 'amount' => '25.00'))));

    // Pending row remains STATUS_CREATING
    $pendingRow = $snapshots->findLatestByBlestaInvoiceId(200);
    assertSame(CheckoutService::STATUS_CREATING, $pendingRow['status']);

    // Retry replays original intent without minting duplicate external_order_id
    $res = $checkout->createCheckout('88', '25.00', 'USD', array(array('id' => 200, 'amount' => '25.00')));
    assertSame('inv_recovered_200', $res['paymos_invoice_id']);
    assertSame(2, count($postCalls));
    assertSame($postCalls[0]['external_order_id'], $postCalls[1]['external_order_id'], 'Must replay exact same external_order_id.');
    assertSame('25', $postCalls[1]['amount']);
});

runTest('3.3 Checkout InvoiceReplacement blocks open/paid/404 and replaces only after closed + blocks paid_late_noted', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $snapshots = new SnapshotStore($db);

    $seed = function (int $blestaId, string $paymosId, string $status = 'awaiting_client', ?string $paidLate = null) use ($snapshots): void {
        $snapshots->save(array(
            'external_order_id' => 'blesta_inv_' . $blestaId . '_r0',
            'paymos_invoice_id' => $paymosId,
            'platform_ref' => 'inv_' . $blestaId,
            'blesta_invoice_id' => $blestaId,
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'sandbox',
            'project_id' => 'prj_sandbox_blesta_001',
            'payment_url' => 'https://checkout.paymos.io/inv/' . $paymosId,
            'status' => $status,
            'paid_late_noted' => $paidLate,
            'renewal_attempt' => 0,
        ));
    };

    // Case A: Old invoice still awaiting_payment (cancel 409, GET awaiting_payment) -> blocked!
    $seed(301, 'inv_open_301');
    $openTransport = new MockTransport(array(
        jsonResponse(409, array('title' => 'Cannot cancel', 'code' => 'invoice_cannot_be_cancelled')),
        jsonResponse(200, array('invoice_id' => 'inv_open_301', 'status' => 'awaiting_payment')),
    ));
    $coOpen = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $openTransport));
    assertThrows(InvoiceReplacementBlockedException::class, static fn () => $coOpen->createCheckout('10', '55.00', 'USD', array(array('id' => 301, 'amount' => '55.00'))));

    // Case B: Old invoice 404 -> fail-closed blocked!
    $seed(302, 'inv_404_302');
    $notFoundTransport = new MockTransport(array(
        jsonResponse(404, array('title' => 'Not Found', 'code' => 'not_found')),
    ));
    $co404 = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $notFoundTransport));
    assertThrows(InvoiceReplacementBlockedException::class, static fn () => $co404->createCheckout('10', '55.00', 'USD', array(array('id' => 302, 'amount' => '55.00'))));

    // Case C: Old invoice paid -> never reused or cancelled; the amount Blesta still asks for gets a new attempt (CMSP-08).
    $seed(303, 'inv_paid_303', 'paid');
    $paidTransport = new MockTransport(array(
        jsonResponse(201, array('invoice_id' => 'inv_next_303', 'payment_url' => 'https://checkout.paymos.io/inv/inv_next_303', 'status' => 'awaiting_client')),
    ));
    $coNext = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $paidTransport));
    $next = $coNext->createCheckout('10', '55.00', 'USD', array(array('id' => 303, 'amount' => '55.00')));
    assertSame('blesta_inv_303_r1', $next['external_order_id']);
    assertSame(1, count($paidTransport->requests()), 'Only the new create is sent; the paid invoice is left alone.');

    // Case D: paid_late_noted -> blocked!
    $coPaid = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), new MockTransport(array())));
    $seed(304, 'inv_late_304', 'expired', 'evt_late_1');
    assertThrows(InvoiceReplacementBlockedException::class, static fn () => $coPaid->createCheckout('10', '49.90', 'USD', array(array('id' => 304, 'amount' => '49.90'))));

    // Case E: Old invoice cleanly cancelled -> replacement minted with r1!
    $seed(305, 'inv_cancel_305');
    $replaceTransport = new MockTransport(array(
        jsonResponse(200, array('invoice_id' => 'inv_cancel_305', 'status' => 'cancelled')),
        jsonResponse(200, array('invoice_id' => 'inv_new_305', 'payment_url' => 'https://checkout.paymos.io/inv/inv_new_305', 'status' => 'awaiting_client')),
    ));
    $coReplace = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $replaceTransport));
    $replaced = $coReplace->createCheckout('10', '55.00', 'USD', array(array('id' => 305, 'amount' => '55.00')));
    assertSame('inv_new_305', $replaced['paymos_invoice_id']);
    assertSame('blesta_inv_305_r1', $replaced['external_order_id']);
});

runTest('3.4 RFC 9457 problem+json API error surfaces safe scrubbed message without marking paid', function () {
    $db = new InMemoryDbDriver();
    (new CredentialStore($db))->saveCredentials(sampleCredentials());
    $transport = new MockTransport(array(
        new HttpResponse(400, json_encode(array(
            'type' => 'https://paymos.io/errors/validation',
            'title' => 'Validation Error',
            'status' => 400,
            'detail' => 'Unsupported currency XXX (leaked sk_test_blesta_sandbox_secret_987654)',
            'code' => 'validation_error',
            'field' => 'currency',
        )), array('Content-Type' => 'application/problem+json')),
    ));
    $gw = makeGateway($db, $transport);
    $gw->setCurrency('XXX');
    $html = $gw->buildProcess(array('client_id' => '9'), '15.00', array(array('id' => 399, 'amount' => '15.00')));
    assertSame('', $html);
    $errs = $gw->errors();
    assertTrue(is_array($errs) && isset($errs['checkout']['failed']));
    assertTrue(strpos($errs['checkout']['failed'], 'sk_test_') === false, 'Secrets must be redacted from error messages.');
    assertTrue(strpos($errs['checkout']['failed'], '(currency)') !== false, 'Actionable field should be surfaced on en_us.');
});

// ── Group 4: Webhooks & MultiEnvironmentWebhookVerifier ─────────────────────

runTest('4.1 Webhook verifies Sandbox & Live with dual-v1=, rejects forged/stale (401), handles 200 duplicate & 409 in-flight', function () {
    $db = new InMemoryDbDriver();
    $now = 1764010000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $eventStore = new PdoEventStore($db, $clock);

    // Seed Live snapshot
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_401_r0',
        'paymos_invoice_id' => 'inv_live_401',
        'platform_ref' => 'inv_401',
        'blesta_invoice_id' => 401,
        'client_id' => '55',
        'payload_meta' => json_encode(array('invoices' => array(array('id' => 401, 'amount' => '49.9')))),
        'amount' => '49.9',
        'currency' => 'USD',
        'environment' => 'live',
        'project_id' => 'prj_live_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_live_401',
        'status' => 'awaiting_client',
        'created_at' => $now - 60,
    ));

    $invoicePayload = array(
        'invoice_id' => 'inv_live_401',
        'project_id' => 'prj_live_blesta_001',
        'status' => 'paid',
        'is_test' => false,
        'order' => array('external_id' => 'blesta_inv_401_r0', 'amount' => '49.90', 'currency' => 'USD'),
        'payment' => array('tx_hash' => '0xdeadbeef401', 'currency' => 'USDT', 'network' => 'arbitrum'),
    );
    $webhookBody = json_encode(array(
        'event_id' => 'evt_live_401',
        'event_type' => 'invoice.paid',
        'version' => 1,
        'occurred_at' => $now,
        'data' => $invoicePayload,
    ));

    $transport = new MockTransport(array(
        jsonResponse(200, $invoicePayload),
    ));
    $config = Config::fromStore(array('mode' => 'sandbox'), $credStore); // Even while mode=sandbox, live webhook works!
    $processor = new CallbackProcessor(
        $config,
        $snapshots,
        $eventStore,
        static fn (Config $c, string $env): Client => new Client($c->clientConfig($env), $transport),
        static fn (): array => array('paid' => false, 'amount' => '49.9', 'currency' => 'USD'),
        null,
        null,
        $clock
    );

    // 1. Forged signature -> 401
    $forged = $processor->handle($webhookBody, array('x-webhook-signature' => 't=' . $now . ',v1=0000'));
    assertSame(401, $forged['http_status']);

    // 2. Stale timestamp (>300s) -> 401
    $staleSig = signWebhook($webhookBody, 'whsec_live_blesta_secret_001', $now - 305);
    $stale = $processor->handle($webhookBody, array('X-Webhook-Signature' => $staleSig));
    assertSame(401, $stale['http_status']);

    // 3. Valid dual-v1= rotation signature -> 200 approved with confirmed tx_hash
    $dualSig = signWebhook($webhookBody, 'whsec_old_rotated_secret', $now, 'whsec_live_blesta_secret_001');
    $ok = $processor->handle($webhookBody, array('HTTP_X_WEBHOOK_SIGNATURE' => $dualSig));
    assertSame(200, $ok['http_status']);
    assertSame('live', $ok['environment']);
    assertSame('approved', $ok['transaction']['status']);
    assertSame('0xdeadbeef401', $ok['transaction']['transaction_id']);

    // 4. Committed duplicate -> 200 duplicate=true, transaction=null
    $dup = $processor->handle($webhookBody, $dualSig);
    assertSame(200, $dup['http_status']);
    assertSame(true, $dup['duplicate']);
    assertSame(null, $dup['transaction']);

    // 5. Concurrent in-flight delivery -> 409 Conflict
    $inFlightEventId = 'evt_inflight_402';
    $eventStore->remember($inFlightEventId, 604800); // Processing, NOT committed!
    $inFlightBody = json_encode(array(
        'event_id' => $inFlightEventId,
        'event_type' => 'invoice.paid',
        'version' => 1,
        'occurred_at' => $now,
        'data' => $invoicePayload,
    ));
    $inFlightSig = signWebhook($inFlightBody, 'whsec_live_blesta_secret_001', $now);
    $conflict = $processor->handle($inFlightBody, $inFlightSig);
    assertSame(409, $conflict['http_status']);
});

runTest('4.2 Webhook holds manual_review on amount mismatch (400+release), guards paid rollback, and treats paid_late as note-only', function () {
    $db = new InMemoryDbDriver();
    $now = 1764020000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $eventStore = new PdoEventStore($db, $clock);
    $config = Config::fromStore(array('mode' => 'sandbox'), $credStore);

    // Snapshot 410: amount mismatch (invoice changed in Blesta to 60.00)
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_410_r0',
        'paymos_invoice_id' => 'inv_sbx_410',
        'platform_ref' => 'inv_410',
        'blesta_invoice_id' => 410,
        'client_id' => '12',
        'amount' => '49.9',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_sbx_410',
        'status' => 'awaiting_client',
        'created_at' => $now - 60,
    ));
    $inv410 = array(
        'invoice_id' => 'inv_sbx_410',
        'project_id' => 'prj_sandbox_blesta_001',
        'status' => 'paid',
        'is_test' => true,
        'order' => array('external_id' => 'blesta_inv_410_r0', 'amount' => '49.9', 'currency' => 'USD'),
    );
    $body410 = json_encode(array('event_id' => 'evt_mismatch_410', 'event_type' => 'invoice.paid', 'version' => 1, 'occurred_at' => $now, 'data' => $inv410));
    $procMismatch = new CallbackProcessor(
        $config,
        $snapshots,
        $eventStore,
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), new MockTransport(array(jsonResponse(200, $inv410)))),
        static fn (): array => array('paid' => false, 'amount' => '60.00', 'currency' => 'USD'),
        null,
        null,
        $clock
    );
    $res410 = $procMismatch->handle($body410, signWebhook($body410, 'whsec_sandbox_blesta_secret_001', $now));
    assertSame(400, $res410['http_status']);
    assertSame(null, $res410['transaction']);
    assertSame(false, $eventStore->isCommitted('evt_mismatch_410'), 'Mismatch must release dedup lock.');
    $meta410 = SnapshotStore::decodeMeta($snapshots->findByInvoiceId('inv_sbx_410')['payload_meta']);
    assertSame(true, $meta410['manual_review']);

    // Snapshot 411: Already paid in Blesta -> late cancel must NOT roll back
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_411_r0',
        'paymos_invoice_id' => 'inv_sbx_411',
        'platform_ref' => 'inv_411',
        'blesta_invoice_id' => 411,
        'amount' => '20',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_sbx_411',
        'status' => 'confirming',
        'created_at' => $now - 60,
    ));
    $inv411 = array(
        'invoice_id' => 'inv_sbx_411',
        'project_id' => 'prj_sandbox_blesta_001',
        'status' => 'cancelled',
        'is_test' => true,
        'order' => array('external_id' => 'blesta_inv_411_r0', 'amount' => '20', 'currency' => 'USD'),
    );
    $body411 = json_encode(array('event_id' => 'evt_rollback_411', 'event_type' => 'invoice.cancelled', 'version' => 1, 'occurred_at' => $now, 'data' => $inv411));
    $procPaid = new CallbackProcessor(
        $config,
        $snapshots,
        $eventStore,
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), new MockTransport(array(jsonResponse(200, $inv411)))),
        static fn (): array => array('paid' => true, 'amount' => '20', 'currency' => 'USD'),
        null,
        null,
        $clock
    );
    $res411 = $procPaid->handle($body411, signWebhook($body411, 'whsec_sandbox_blesta_secret_001', $now));
    assertSame(200, $res411['http_status']);
    assertSame('ignored_already_paid', $res411['status']);
    assertSame('confirming', $snapshots->findByInvoiceId('inv_sbx_411')['status'], 'The guard keeps the recorded Paymos status (CMSP-11).');

    // Snapshot 412: invoice.paid_late on expired invoice -> note-only, paid_late_noted set, NO payment transaction
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_412_r0',
        'paymos_invoice_id' => 'inv_sbx_412',
        'platform_ref' => 'inv_412',
        'blesta_invoice_id' => 412,
        'amount' => '30',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_sbx_412',
        'status' => 'expired',
        'final_status' => 'expired',
        'created_at' => $now - 3600,
    ));
    $inv412 = array(
        'invoice_id' => 'inv_sbx_412',
        'project_id' => 'prj_sandbox_blesta_001',
        'status' => 'expired',
        'paid_late_at' => $now - 5,
        'is_test' => true,
        'order' => array('external_id' => 'blesta_inv_412_r0', 'amount' => '30', 'currency' => 'USD'),
    );
    $body412 = json_encode(array('event_id' => 'evt_late_412', 'event_type' => 'invoice.paid_late', 'version' => 1, 'occurred_at' => $now, 'data' => $inv412));
    $procLate = new CallbackProcessor(
        $config,
        $snapshots,
        $eventStore,
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), new MockTransport(array(jsonResponse(200, $inv412)))),
        null,
        null,
        null,
        $clock
    );
    $res412 = $procLate->handle($body412, signWebhook($body412, 'whsec_sandbox_blesta_secret_001', $now));
    assertSame(200, $res412['http_status']);
    assertSame('paid_late_noted', $res412['status']);
    assertSame(null, $res412['transaction']);
    $row412 = $snapshots->findByInvoiceId('inv_sbx_412');
    assertSame('evt_late_412', $row412['paid_late_noted']);
    assertSame('expired', $row412['status'], 'paid_late must NOT change invoice status.');
});

// ── Group 5: Cron Reconciler & Companion PaymosPlugin ───────────────────────

runTest('5.1 Reconciler sweeps missed 24h unpaid invoices, applies payment, and enforces 600s throttle', function () {
    $db = new InMemoryDbDriver();
    $now = 1764050000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);

    // Recent unpaid invoice (within 24h)
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_501_r0',
        'paymos_invoice_id' => 'inv_rec_501',
        'platform_ref' => 'inv_501',
        'blesta_invoice_id' => 501,
        'client_id' => '21',
        'payload_meta' => json_encode(array('invoices' => array(array('id' => 501, 'amount' => '19.99')))),
        'amount' => '19.99',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_rec_501',
        'status' => 'awaiting_payment',
        'created_at' => $now - 1800,
    ));
    // Stale >24h invoice (must be excluded)
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_502_r0',
        'paymos_invoice_id' => 'inv_rec_502',
        'platform_ref' => 'inv_502',
        'blesta_invoice_id' => 502,
        'amount' => '10',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_rec_502',
        'status' => 'awaiting_client',
        'created_at' => $now - 90000,
    ));

    $appliedTx = array();
    $transport = new MockTransport(array(
        jsonResponse(200, array(
            'invoice_id' => 'inv_rec_501',
            'project_id' => 'prj_sandbox_blesta_001',
            'status' => 'paid',
            'is_test' => true,
            'order' => array('external_id' => 'blesta_inv_501_r0', 'amount' => '19.99', 'currency' => 'USD'),
            'payment' => array('tx_hash' => '0xreconciled501'),
        )),
        // RefundDiscovery empty pages for sandbox + live triggered by PaymosPlugin::cron()
        jsonResponse(200, array('items' => array(), 'next_cursor' => null)),
        jsonResponse(200, array('items' => array(), 'next_cursor' => null)),
        // Second cron run (throttled for invoice reconciler, runs discovery)
        jsonResponse(200, array('items' => array(), 'next_cursor' => null)),
        jsonResponse(200, array('items' => array(), 'next_cursor' => null)),
        // Third cron run after +600s
        jsonResponse(200, array('items' => array(), 'next_cursor' => null)),
        jsonResponse(200, array('items' => array(), 'next_cursor' => null)),
    ));

    $plugin = new PaymosPlugin();
    $plugin->setDbDriver($db);
    $plugin->setClock($clock);
    $plugin->setMeta(array('mode' => 'sandbox'));
    $plugin->setClientFactory(static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));
    $plugin->setBillingStateResolver(static fn (): array => array('paid' => false, 'amount' => '19.99', 'currency' => 'USD'));
    $plugin->setPaymentApplier(function (array $tx) use (&$appliedTx): void {
        $appliedTx[] = $tx;
    });

    $run1 = $plugin->cron(PaymosPlugin::CRON_KEY);
    assertSame(true, $run1['reconcile']['ran']);
    assertSame(1, $run1['reconcile']['checked']);
    assertSame(1, $run1['reconcile']['applied']);
    assertSame(true, $run1['refunds_ran']);
    assertSame(1, count($appliedTx));
    assertSame('0xreconciled501', $appliedTx[0]['transaction_id']);

    // Run again 300s later -> throttled!
    $now += 300;
    $run2 = $plugin->cron(PaymosPlugin::CRON_KEY);
    assertSame(false, $run2['reconcile']['ran'], 'Reconciler must throttle within 600s.');

    // Run after +600s -> allowed again, 0 unpaid left (no double credit)
    $now += 301;
    $run3 = $plugin->cron(PaymosPlugin::CRON_KEY);
    assertSame(true, $run3['reconcile']['ran']);
    assertSame(0, $run3['reconcile']['applied']);
    assertSame(1, count($appliedTx));
});

runTest('5.2 R1/R8: PaymosPlugin::cron() uses live Invoices/GatewayManager models without test resolver & Reconciler enforces is_test + non-empty fields', function () {
    $db = new InMemoryDbDriver();
    $now = 1764055000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);

    // Invoice 510: unpaid and matching -> must apply via Transactions::add with gateway_id = 42
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_510_r0',
        'paymos_invoice_id' => 'inv_live_510',
        'platform_ref' => 'inv_510',
        'blesta_invoice_id' => 510,
        'client_id' => '51',
        'payload_meta' => json_encode(array('invoices' => array(array('id' => 510, 'amount' => '25.00')))),
        'amount' => '25.00',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_live_510',
        'status' => 'awaiting_payment',
        'created_at' => $now - 600,
    ));
    // Invoice 511: already paid in Blesta -> live Invoices::get() double-payment guard skips Transactions::add
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_511_r0',
        'paymos_invoice_id' => 'inv_live_511',
        'platform_ref' => 'inv_511',
        'blesta_invoice_id' => 511,
        'client_id' => '52',
        'amount' => '30.00',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_live_511',
        'status' => 'awaiting_payment',
        'created_at' => $now - 500,
    ));
    // Invoice 512: deleted from Blesta -> marked blesta_invoice_missing
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_512_r0',
        'paymos_invoice_id' => 'inv_live_512',
        'platform_ref' => 'inv_512',
        'blesta_invoice_id' => 512,
        'client_id' => '53',
        'amount' => '40.00',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_live_512',
        'status' => 'awaiting_payment',
        'created_at' => $now - 400,
    ));
    // Invoice 513: amount edited in Blesta (99.00 vs 50.00) -> AmountGuard blocks settlement
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_513_r0',
        'paymos_invoice_id' => 'inv_live_513',
        'platform_ref' => 'inv_513',
        'blesta_invoice_id' => 513,
        'client_id' => '54',
        'amount' => '50.00',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_live_513',
        'status' => 'awaiting_payment',
        'created_at' => $now - 300,
    ));
    // Invoice 514: API returns wrong is_test=false for sandbox snapshot -> R8 rejects match
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_514_r0',
        'paymos_invoice_id' => 'inv_live_514',
        'platform_ref' => 'inv_514',
        'blesta_invoice_id' => 514,
        'client_id' => '55',
        'amount' => '60.00',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_live_514',
        'status' => 'awaiting_payment',
        'created_at' => $now - 200,
    ));
    // Invoice 515: API returns empty project_id / order.amount -> R8 rejects match
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_515_r0',
        'paymos_invoice_id' => 'inv_live_515',
        'platform_ref' => 'inv_515',
        'blesta_invoice_id' => 515,
        'client_id' => '56',
        'amount' => '70.00',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_live_515',
        'status' => 'awaiting_payment',
        'created_at' => $now - 100,
    ));

    $addedTransactions = array();
    $appliedLinks = array();
    $prevModels = Loader::$models;
    Loader::$models['Invoices'] = new class {
        public function get(int $id) {
            if ($id === 510) {
                return (object) array('id' => 510, 'status' => 'active', 'date_closed' => null, 'total' => '25.00', 'due' => '25.00', 'currency' => 'USD');
            }
            if ($id === 511) {
                return (object) array('id' => 511, 'status' => 'active', 'date_closed' => '2026-11-25 10:00:00', 'total' => '30.00', 'due' => '0.00', 'currency' => 'USD');
            }
            if ($id === 512) {
                return false;
            }
            if ($id === 513) {
                return (object) array('id' => 513, 'status' => 'active', 'date_closed' => null, 'total' => '99.00', 'due' => '99.00', 'currency' => 'USD');
            }
            return (object) array('id' => $id, 'status' => 'active', 'date_closed' => null, 'total' => '60.00', 'due' => '60.00', 'currency' => 'USD');
        }
    };
    Loader::$models['GatewayManager'] = new class {
        public function getByClass(string $class, int $companyId) {
            return $class === 'paymos' ? array((object) array('id' => 42, 'class' => 'paymos', 'company_id' => $companyId)) : array();
        }
    };
    Loader::$models['Transactions'] = new class($addedTransactions, $appliedLinks) {
        public $added;
        public $applied;
        public function __construct(&$added, &$applied) { $this->added = &$added; $this->applied = &$applied; }
        public function add(array $vars): int { $this->added[] = $vars; return 9510; }
        public function apply(int $txId, array $vars): void { $this->applied[] = array('tx_id' => $txId, 'vars' => $vars); }
    };

    try {
        $transport = new SpyTransport(function (array $req): HttpResponse {
            $path = (string) parse_url($req['url'], PHP_URL_PATH);
            $map = array(
                '/v1/invoices/inv_live_510' => array('invoice_id' => 'inv_live_510', 'project_id' => 'prj_sandbox_blesta_001', 'status' => 'paid', 'is_test' => true, 'order' => array('external_id' => 'blesta_inv_510_r0', 'amount' => '25.00', 'currency' => 'USD'), 'payment' => array('tx_hash' => '0xtx510')),
                '/v1/invoices/inv_live_511' => array('invoice_id' => 'inv_live_511', 'project_id' => 'prj_sandbox_blesta_001', 'status' => 'paid', 'is_test' => true, 'order' => array('external_id' => 'blesta_inv_511_r0', 'amount' => '30.00', 'currency' => 'USD'), 'payment' => array('tx_hash' => '0xtx511')),
                '/v1/invoices/inv_live_512' => array('invoice_id' => 'inv_live_512', 'project_id' => 'prj_sandbox_blesta_001', 'status' => 'paid', 'is_test' => true, 'order' => array('external_id' => 'blesta_inv_512_r0', 'amount' => '40.00', 'currency' => 'USD'), 'payment' => array('tx_hash' => '0xtx512')),
                '/v1/invoices/inv_live_513' => array('invoice_id' => 'inv_live_513', 'project_id' => 'prj_sandbox_blesta_001', 'status' => 'paid', 'is_test' => true, 'order' => array('external_id' => 'blesta_inv_513_r0', 'amount' => '50.00', 'currency' => 'USD'), 'payment' => array('tx_hash' => '0xtx513')),
                '/v1/invoices/inv_live_514' => array('invoice_id' => 'inv_live_514', 'project_id' => 'prj_sandbox_blesta_001', 'status' => 'paid', 'is_test' => false, 'order' => array('external_id' => 'blesta_inv_514_r0', 'amount' => '60.00', 'currency' => 'USD'), 'payment' => array('tx_hash' => '0xtx514')),
                '/v1/invoices/inv_live_515' => array('invoice_id' => 'inv_live_515', 'project_id' => '', 'status' => 'paid', 'is_test' => true, 'order' => array('external_id' => 'blesta_inv_515_r0', 'amount' => '', 'currency' => 'USD'), 'payment' => array('tx_hash' => '0xtx515')),
            );
            if (isset($map[$path])) {
                return jsonResponse(200, $map[$path]);
            }
            if ($path === '/v1/refunds') {
                return jsonResponse(200, array('items' => array(), 'next_cursor' => null));
            }
            throw new \RuntimeException('Unexpected request: ' . $path);
        });

        $plugin = new PaymosPlugin();
        $plugin->setDbDriver($db);
        $plugin->setClock($clock);
        $plugin->setMeta(array('mode' => 'sandbox'));
        $plugin->setClientFactory(static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));
        // Explicitly NO setBillingStateResolver() and NO setPaymentApplier() — tests real production Blesta wiring!
        $res = $plugin->cron(PaymosPlugin::CRON_KEY);

        assertSame(true, $res['reconcile']['ran']);
        assertSame(6, $res['reconcile']['checked']);
        assertSame(1, count($addedTransactions), 'ONLY unpaid matching invoice #510 may trigger Transactions::add.');
        assertSame(42, $addedTransactions[0]['gateway_id'], 'Transactions::add MUST include resolved gateway_id for Paymos.');
        assertSame('0xtx510', $addedTransactions[0]['transaction_id']);
        assertSame(1, count($appliedLinks));
        assertSame(SnapshotStore::STATUS_BLESTA_INVOICE_MISSING, $snapshots->findByExternalOrderId('blesta_inv_512_r0')['status'], 'Deleted Blesta invoice must transition to blesta_invoice_missing.');
        assertSame('awaiting_payment', $snapshots->findByExternalOrderId('blesta_inv_514_r0')['status'], 'Mismatched is_test must be rejected by Reconciler.');
        assertSame('awaiting_payment', $snapshots->findByExternalOrderId('blesta_inv_515_r0')['status'], 'Empty API fields must be rejected by Reconciler.');
    } finally {
        Loader::$models = $prevModels;
    }
});

// ── Group 6: Full Refunds, Async Projection, Overpayment & RefundDiscovery ──

runTest('6.1 RefundIntentStore implements both interfaces with lock, CAS bindWithdrawal, and candidate retries', function () {
    $db = new InMemoryDbDriver();
    $credStore = new CredentialStore($db);
    $store = new RefundIntentStore($db, $credStore);
    assertTrue($store instanceof RefundIntentStoreInterface);
    assertTrue($store instanceof RefundCandidateStoreInterface);

    // Contended lock throws EventInProgressException
    $db->externallyHeldLocks['paymos-blesta-inv:600'] = true;
    assertThrows(EventInProgressException::class, static fn () => $store->withPaymentLock('600', static fn () => null));
    unset($db->externallyHeldLocks['paymos-blesta-inv:600']);

    $store->withPaymentLock('600', function () use ($store): void {
        $intent = new \Paymos\Plugin\RefundIntent(array(
            'operation_id' => RefundIntentStore::uuid(),
            'request_token' => RefundIntentStore::uuid(),
            'installation_id' => $store->installationId(),
            'platform' => 'blesta',
            'native_payment_key' => '600',
            'invoice_id' => 'inv_600',
            'project_id' => 'prj_sandbox_blesta_001',
            'environment' => 'sandbox',
            'mode' => RefundStates::FULL,
            'full_draft' => array('amount_minor' => '4990', 'currency' => 'USD'),
        ));
        $created = $store->createIntent($intent);
        $store->recordReadCandidate($created->operationId(), 'wd_cand_1', 1);
        $loaded = $store->getIntent($created->operationId());
        assertSame('wd_cand_1', $loaded->candidateWithdrawalId());
        assertSame(1, $loaded->readRetryCount());

        assertSame('bound', $store->bindWithdrawal($created->operationId(), 'wd_600'));
        assertSame('same', $store->bindWithdrawal($created->operationId(), 'wd_600'));
        assertSame('conflict', $store->bindWithdrawal($created->operationId(), 'wd_other'));

        $claim1 = $store->claimEffect($created->operationId(), 'confirmation', 1);
        assertSame('claimed', $claim1->kind());
        $store->recordEffectOutcome($created->operationId(), 'confirmation', 1, RefundStates::EFFECT_SKIPPED);
        $claim2 = $store->claimEffect($created->operationId(), 'confirmation', 1);
        assertSame('already_started', $claim2->kind());
    });
});

runTest('6.2 Full refund async lifecycle: nativeRefund() returns null while pending (0 premature debit), applies Transactions::edit once on completed webhook', function () {
    $db = new InMemoryDbDriver();
    $now = 1764060000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $eventStore = new PdoEventStore($db, $clock);
    $intentStore = new RefundIntentStore($db, $credStore, $clock);
    $config = Config::fromStore(array('mode' => 'sandbox'), $credStore);

    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_610_r0',
        'paymos_invoice_id' => 'inv_rf_610',
        'platform_ref' => 'inv_610',
        'blesta_invoice_id' => 610,
        'client_id' => '33',
        'amount' => '49.90',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_rf_610',
        'transaction_id' => '0xtx610',
        'status' => 'paid',
        'final_status' => 'paid',
        'created_at' => $now - 600,
    ));

    $blestaTx = array(
        'id' => '9001',
        'client_id' => '33',
        'transaction_id' => '0xtx610',
        'reference_id' => 'inv_rf_610',
        'amount' => '49.90',
        'currency' => 'USD',
        'status' => 'approved',
        'notes' => '',
    );
    $editCalls = 0;

    $native = function (string $cmd, array $input) use (&$blestaTx, &$editCalls, $snapshots): array {
        if ($cmd === 'snapshot') {
            return array(
                'invoice' => array('id' => 610, 'client_id' => '33', 'status' => 'active', 'total' => '49.90', 'currency' => 'USD'),
                'transaction' => $blestaTx,
                'mapping' => $snapshots->findPaidByBlestaInvoiceId(610),
            );
        }
        if ($cmd === 'marker') {
            if ($blestaTx['status'] === 'refunded' && strpos($blestaTx['notes'], (string) $input['marker']) !== false) {
                return array($blestaTx);
            }
            return array();
        }
        if ($cmd === 'Transactions::edit') {
            $editCalls++;
            $blestaTx['status'] = $input['vars']['status'];
            $blestaTx['notes'] = $input['vars']['notes'];
            return array('result' => 'success');
        }
        return array();
    };

    $invoiceApi = array(
        'invoice_id' => 'inv_rf_610',
        'project_id' => 'prj_sandbox_blesta_001',
        'status' => 'paid',
        'is_test' => true,
        'order' => array('external_id' => 'blesta_inv_610_r0', 'amount' => '49.90', 'currency' => 'USD'),
        'refund_summary' => array(
            'can_refund_full' => true,
            'can_refund_overpayment' => false,
            'full_refund_amount' => '49.9',
            'overpayment_refund_amount' => null,
        ),
    );

    $refundStatus = 'signed'; // starts pending/processing!
    $postCalls = array();
    $transport = new SpyTransport(function (array $req) use ($invoiceApi, &$refundStatus, &$postCalls): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($req['method'] === 'POST' && $path === '/v1/refunds') {
            $body = json_decode((string) $req['body'], true);
            $postCalls[] = $body;
            return jsonResponse(201, array(
                'withdrawal_id' => 'wd_rf_610',
                'invoice_id' => 'inv_rf_610',
                'mode' => 'full',
                'external_order_id' => $body['external_order_id'],
                'status' => $refundStatus,
            ));
        }
        if ($req['method'] === 'GET' && $path === '/v1/refunds/wd_rf_610') {
            return jsonResponse(200, array(
                'withdrawal_id' => 'wd_rf_610',
                'invoice_id' => 'inv_rf_610',
                'project_id' => 'prj_sandbox_blesta_001',
                'is_test' => true,
                'mode' => 'full',
                'external_order_id' => $postCalls[0]['external_order_id'],
                'status' => $refundStatus,
                'amount' => '49.9',
                'currency' => 'USDT',
                'network' => 'arbitrum',
                'first_sender' => '0xsender610',
            ));
        }
        if ($req['method'] === 'GET' && $path === '/v1/invoices/inv_rf_610') {
            return jsonResponse(200, $invoiceApi);
        }
        throw new \RuntimeException('Unexpected request: ' . $req['method'] . ' ' . $path);
    });

    // Step 1: Call native Paymos::refund() while Paymos refund is processing ('signed')
    $gw = makeGateway($db, $transport, $clock, 'sandbox');
    $gw->setRefundNativeHandler($native);
    $nativeRes = $gw->refund('inv_rf_610', '0xtx610', '49.90');

    assertSame(null, $nativeRes, 'NonmerchantGateway::refund() MUST return null while pending/processing.');
    assertTrue(is_array($gw->errors()) && isset($gw->errors()['refund']['status']), 'Informational Input error must be set.');
    assertSame(0, $editCalls, 'ZERO premature Transactions::edit debit while refund is pending!');
    assertSame('approved', $blestaTx['status']);
    assertSame(1, count($postCalls));
    assertSame(array('invoice_id', 'mode', 'external_order_id'), array_keys($postCalls[0]), 'POST /v1/refunds MUST send strictly 3 fields.');

    // Step 2: Refund reaches completed -> withdrawal.completed webhook triggers applyCompleted()!
    $refundStatus = 'completed';
    $adapter = new RefundAdapter(
        $config,
        $credStore,
        $snapshots,
        $intentStore,
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport),
        $native,
        null,
        $clock
    );
    $processor = new CallbackProcessor(
        $config,
        $snapshots,
        $eventStore,
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport),
        null,
        static function ($evt, string $env) use ($adapter): void {
            $adapter->callback($evt, $env);
        },
        null,
        $clock
    );

    $wdWebhook = json_encode(array(
        'event_id' => 'evt_wd_done_610',
        'event_type' => 'withdrawal.completed',
        'version' => 1,
        'occurred_at' => $now + 10,
        'data' => array(
            'type' => 'refund',
            'withdrawal_id' => 'wd_rf_610',
            'invoice_id' => 'inv_rf_610',
            'project_id' => 'prj_sandbox_blesta_001',
            'is_test' => true,
            'mode' => 'full',
            'external_order_id' => $postCalls[0]['external_order_id'],
            'status' => 'completed',
        ),
    ));
    $cbRes = $processor->handle($wdWebhook, signWebhook($wdWebhook, 'whsec_sandbox_blesta_secret_001', $now + 10), $now + 10);
    assertSame(200, $cbRes['http_status']);
    assertSame('refund_processed', $cbRes['status']);
    assertSame(1, $editCalls, 'Transactions::edit must be applied EXACTLY ONCE on verified completed.');
    assertSame('refunded', $blestaTx['status']);
    assertTrue(strpos($blestaTx['notes'], 'paymos_refund_wd_rf_610') !== false);

    // Step 3: Re-reconciling must be 100% idempotent (still 1 edit call!)
    $intents = $intentStore->forPayment('610');
    assertSame(RefundStates::COMPLETED, $intents[0]->cashState());
    assertSame(RefundStates::PROJECTION_APPLIED, $intents[0]->projectionState());
    $adapter->reconcile($intents[0]->operationId());
    assertSame(1, $editCalls, 'Idempotent reconcile must never apply Transactions::edit twice.');
});

runTest('6.3 Overpayment refund on paid_over invoice via AdminRefunds keeps Blesta invoice paid and rejects partial/unmanaged inputs', function () {
    $db = new InMemoryDbDriver();
    $now = 1764070000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);

    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_620_r0',
        'paymos_invoice_id' => 'inv_over_620',
        'platform_ref' => 'inv_620',
        'blesta_invoice_id' => 620,
        'client_id' => '44',
        'amount' => '100',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_over_620',
        'transaction_id' => '0xtx620',
        'status' => 'paid_over',
        'final_status' => 'paid_over',
        'created_at' => $now - 600,
    ));

    $txEdits = 0;
    $overNotes = array();
    $native = function (string $cmd, array $input) use ($snapshots, &$txEdits, &$overNotes): array {
        if ($cmd === 'snapshot') {
            return array(
                'invoice' => array('id' => 620, 'client_id' => '44', 'status' => 'active', 'total' => '100', 'currency' => 'USD'),
                'transaction' => array('id' => '9620', 'client_id' => '44', 'transaction_id' => '0xtx620', 'reference_id' => 'inv_over_620', 'amount' => '100', 'currency' => 'USD', 'status' => 'approved', 'notes' => ''),
                'mapping' => $snapshots->findPaidByBlestaInvoiceId(620),
            );
        }
        if ($cmd === 'Transactions::edit') {
            $txEdits++;
            return array('result' => 'success');
        }
        if ($cmd === 'record_overpayment_note') {
            $overNotes[] = $input;
            return array('result' => 'success');
        }
        return array();
    };

    $lastExt = '';
    $transport = new SpyTransport(function (array $req) use (&$lastExt): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($req['method'] === 'POST' && $path === '/v1/refunds') {
            $body = json_decode((string) $req['body'], true);
            $lastExt = $body['external_order_id'];
            return jsonResponse(201, array('withdrawal_id' => 'wd_over_620', 'status' => 'completed'));
        }
        if ($req['method'] === 'GET' && $path === '/v1/refunds/wd_over_620') {
            return jsonResponse(200, array(
                'withdrawal_id' => 'wd_over_620',
                'invoice_id' => 'inv_over_620',
                'project_id' => 'prj_sandbox_blesta_001',
                'is_test' => true,
                'mode' => 'overpayment',
                'external_order_id' => $lastExt,
                'status' => 'completed',
                'amount' => '12.5',
                'currency' => 'USDT',
                'network' => 'tron',
                'first_sender' => 'TSenderOver620',
            ));
        }
        if ($req['method'] === 'GET' && $path === '/v1/invoices/inv_over_620') {
            return jsonResponse(200, array(
                'invoice_id' => 'inv_over_620',
                'project_id' => 'prj_sandbox_blesta_001',
                'status' => 'paid_over',
                'is_test' => true,
                'order' => array('external_id' => 'blesta_inv_620_r0', 'amount' => '100', 'currency' => 'USD'),
                'refund_summary' => array(
                    'can_refund_full' => true,
                    'can_refund_overpayment' => true,
                    'full_refund_amount' => '112.5',
                    'overpayment_refund_amount' => '12.5',
                ),
            ));
        }
        if ($req['method'] === 'GET' && $path === '/v1/refunds') {
            return jsonResponse(200, array('items' => array(), 'next_cursor' => null));
        }
        throw new \RuntimeException('Unexpected request: ' . $req['method'] . ' ' . $path);
    });

    $controller = new AdminRefunds();
    $controller->setDbDriver($db);
    $controller->setClock($clock);
    $controller->setMeta(array('mode' => 'sandbox'));
    $controller->setClientFactory(static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));
    $controller->setRefundNativeHandler($native);
    $controller->setStaffAuthenticator(static fn (): bool => true);
    $controller->setCsrfVerifier(static fn (string $t): bool => $t === 'csrf_ok');

    // Reject unmanaged fields BEFORE any HTTP call
    $unmanaged = $controller->handleSubmitRequest('POST', array(
        '_csrf_token' => 'csrf_ok',
        'invoice_id' => '620',
        'mode' => 'overpayment',
        'request_token' => CredentialStore::uuidV4(),
        'destination_address' => '0xattacker',
    ));
    assertSame(400, $unmanaged['http_status']);
    assertSame('refund_managed_input_only', $unmanaged['body']['reason']);
    assertSame(0, count($transport->requests));

    // Reject partial amount BEFORE any HTTP call
    $partial = $controller->handleSubmitRequest('POST', array(
        '_csrf_token' => 'csrf_ok',
        'invoice_id' => '620',
        'mode' => 'full',
        'request_token' => CredentialStore::uuidV4(),
        'amount' => '25.00',
    ));
    assertSame(400, $partial['http_status']);
    assertSame('refund_partial_unsupported', $partial['body']['reason']);
    assertSame(0, count($transport->requests));

    // Reject bad CSRF -> 403
    $badCsrf = $controller->handleSubmitRequest('POST', array(
        '_csrf_token' => 'bad',
        'invoice_id' => '620',
        'mode' => 'overpayment',
        'request_token' => CredentialStore::uuidV4(),
    ));
    assertSame(403, $badCsrf['http_status']);

    // Submit valid overpayment refund with UUIDv4 token
    $token = CredentialStore::uuidV4();
    $ok = $controller->handleSubmitRequest('POST', array(
        '_csrf_token' => 'csrf_ok',
        'invoice_id' => '620',
        'mode' => 'overpayment',
        'request_token' => $token,
    ));
    assertSame(200, $ok['http_status']);
    assertSame('overpayment', $ok['body']['mode']);
    assertSame(RefundStates::COMPLETED, $ok['body']['cash_state']);
    assertSame(RefundStates::PROJECTION_APPLIED, $ok['body']['projection_state']);
    assertSame(0, $txEdits, 'Overpayment refund must NEVER change Blesta transaction status to refunded.');
    assertSame(1, count($overNotes), 'Overpayment note must be recorded.');

    // Panel hides overpayment button once completed while full remains available
    $panelRes = $controller->handlePanelRequest(620, 'ru_ru');
    assertSame(200, $panelRes['http_status']);
    assertSame(true, $panelRes['body']['can_refund_full']);
    assertSame(false, $panelRes['body']['can_refund_overpayment']);
});

runTest('6.4 RefundDiscovery discovers and projects Dashboard-initiated refund without POST /v1/refunds', function () {
    $db = new InMemoryDbDriver();
    $now = 1764080000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $intentStore = new RefundIntentStore($db, $credStore, $clock);
    $config = Config::fromStore(array('mode' => 'sandbox'), $credStore);

    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_630_r0',
        'paymos_invoice_id' => 'inv_dash_630',
        'platform_ref' => 'inv_630',
        'blesta_invoice_id' => 630,
        'client_id' => '70',
        'amount' => '80',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_dash_630',
        'transaction_id' => '0xtx630',
        'status' => 'paid',
        'final_status' => 'paid',
        'created_at' => $now - 300,
    ));

    $tx630 = array(
        'id' => '9630',
        'client_id' => '70',
        'transaction_id' => '0xtx630',
        'reference_id' => 'inv_dash_630',
        'amount' => '80',
        'currency' => 'USD',
        'status' => 'approved',
        'notes' => '',
    );
    $editCount = 0;
    $native = function (string $cmd, array $input) use ($snapshots, &$editCount, &$tx630): array {
        if ($cmd === 'snapshot') {
            return array(
                'invoice' => array('id' => 630, 'client_id' => '70', 'status' => 'active', 'total' => '80', 'currency' => 'USD'),
                'transaction' => $tx630,
                'mapping' => $snapshots->findPaidByBlestaInvoiceId(630),
            );
        }
        if ($cmd === 'marker') {
            if ($tx630['status'] === 'refunded' && strpos($tx630['notes'], (string) $input['marker']) !== false) {
                return array($tx630);
            }
            return array();
        }
        if ($cmd === 'Transactions::edit') {
            $editCount++;
            $tx630['status'] = $input['vars']['status'];
            $tx630['notes'] = $input['vars']['notes'];
            return array('result' => 'success');
        }
        return array();
    };

    $postSeen = false;
    $transport = new SpyTransport(function (array $req) use (&$postSeen): HttpResponse {
        if ($req['method'] === 'POST') {
            $postSeen = true;
            throw new \RuntimeException('RefundDiscovery MUST NEVER call POST /v1/refunds!');
        }
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($path === '/v1/refunds') {
            return jsonResponse(200, array(
                'items' => array(
                    array(
                        'withdrawal_id' => 'wd_dash_630',
                        'invoice_id' => 'inv_dash_630',
                        'project_id' => 'prj_sandbox_blesta_001',
                        'is_test' => true,
                        'mode' => 'full',
                        'external_order_id' => 'dashboard_ext_630',
                        'status' => 'completed',
                    ),
                ),
                'next_cursor' => null,
            ));
        }
        if ($path === '/v1/refunds/wd_dash_630') {
            return jsonResponse(200, array(
                'withdrawal_id' => 'wd_dash_630',
                'invoice_id' => 'inv_dash_630',
                'project_id' => 'prj_sandbox_blesta_001',
                'is_test' => true,
                'mode' => 'full',
                'external_order_id' => 'dashboard_ext_630',
                'status' => 'completed',
                'amount' => '80',
                'currency' => 'USDC',
                'network' => 'base',
                'first_sender' => '0xdashsender630',
            ));
        }
        if ($path === '/v1/invoices/inv_dash_630') {
            return jsonResponse(200, array(
                'invoice_id' => 'inv_dash_630',
                'project_id' => 'prj_sandbox_blesta_001',
                'status' => 'paid',
                'is_test' => true,
                'order' => array('external_id' => 'blesta_inv_630_r0', 'amount' => '80', 'currency' => 'USD'),
            ));
        }
        throw new \RuntimeException('Unexpected request: ' . $path);
    });

    $adapter = new RefundAdapter(
        $config,
        $credStore,
        $snapshots,
        $intentStore,
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport),
        $native,
        null,
        $clock
    );

    $discovered = $adapter->discoverExternal('sandbox');
    assertSame(1, $discovered);
    assertSame(false, $postSeen);
    assertSame(1, $editCount, 'Discovered completed full refund must project via Transactions::edit once.');

    // Re-running discovery must not project a second time
    $adapter->discoverExternal('sandbox');
    assertSame(1, $editCount, 'Repeated discovery must be idempotent.');

    $externals = $intentStore->externalForPayment('630');
    assertSame(1, count($externals));
    assertSame('9630', $externals[0]['note_id']);
});

runTest('6.5 R2/R3: AdminRefunds HTML page + JS submit wiring & observeExternalRefund() checks errors/conflicts/markers', function () {
    $db = new InMemoryDbDriver();
    $now = 1764090000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $intentStore = new RefundIntentStore($db, $credStore, $clock);
    $config = Config::fromStore(array('mode' => 'sandbox'), $credStore);

    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_640_r0',
        'paymos_invoice_id' => 'inv_ui_640',
        'platform_ref' => 'inv_640',
        'blesta_invoice_id' => 640,
        'client_id' => '80',
        'amount' => '100',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_ui_640',
        'transaction_id' => '0xtx640',
        'status' => 'paid_over',
        'final_status' => 'paid_over',
        'created_at' => $now - 200,
    ));

    $uiTransport = new SpyTransport(function (array $req): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($path === '/v1/invoices/inv_ui_640') {
            return jsonResponse(200, array(
                'invoice_id' => 'inv_ui_640',
                'project_id' => 'prj_sandbox_blesta_001',
                'status' => 'paid_over',
                'is_test' => true,
                'order' => array('external_id' => 'blesta_inv_640_r0', 'amount' => '100', 'currency' => 'USD'),
                'refund_summary' => array(
                    'can_refund_full' => true,
                    'can_refund_overpayment' => true,
                    'full_refund_amount' => '115',
                    'overpayment_refund_amount' => '15',
                ),
            ));
        }
        if ($path === '/v1/refunds') {
            return jsonResponse(200, array('items' => array(), 'next_cursor' => null));
        }
        throw new \RuntimeException('Unexpected request: ' . $path);
    });

    $uiNative = function (string $cmd, array $input) use ($snapshots): array {
        if ($cmd === 'snapshot') {
            return array(
                'invoice' => array('id' => 640, 'client_id' => '80', 'status' => 'active', 'total' => '100', 'currency' => 'USD'),
                'transaction' => array('id' => '9640', 'client_id' => '80', 'transaction_id' => '0xtx640', 'reference_id' => 'inv_ui_640', 'amount' => '100', 'currency' => 'USD', 'status' => 'approved', 'notes' => ''),
                'mapping' => $snapshots->findPaidByBlestaInvoiceId(640),
            );
        }
        return array();
    };

    $controller = new AdminRefunds();
    $controller->setDbDriver($db);
    $controller->setClock($clock);
    $controller->setMeta(array('mode' => 'sandbox'));
    $controller->setClientFactory(static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $uiTransport));
    $controller->setRefundNativeHandler($uiNative);

    // Unauthenticated staff -> 403 HTML
    $controller->setStaffAuthenticator(static fn (): bool => false);
    $denied = $controller->handleIndexPage(0);
    assertSame(403, $denied['http_status']);

    // Authenticated staff without invoice_id -> 200 HTML lookup page with recent paid invoice #640 & JS submit handler
    $controller->setStaffAuthenticator(static fn (): bool => true);
    $indexPage = $controller->handleIndexPage(0, 'en_us');
    assertSame(200, $indexPage['http_status'], 'AdminRefunds::index() without invoice_id MUST return 200 HTML (not 400 JSON).');
    assertTrue(strpos($indexPage['html'], '<form method="get"') !== false, 'Index page must include invoice lookup form.');
    assertTrue(strpos($indexPage['html'], '?invoice_id=640') !== false, 'Index page must list recent paid Paymos invoices.');
    assertTrue(strpos($indexPage['html'], 'data-submit-url="/admin/plugin/paymos/admin_refunds/submit/"') !== false);
    assertTrue(strpos($indexPage['html'], 'data-csrf-token="' . Form::getCsrfToken() . '"') !== false);
    assertTrue(strpos($indexPage['html'], '.paymos-refund-btn') !== false && strpos($indexPage['html'], 'fetch(submitUrl') !== false, 'Index page must include working JS click/submit handler.');

    // Authenticated staff with paid_over invoice_id -> renders both full & overpayment buttons
    $detailPage = $controller->handleIndexPage(640, 'en_us');
    assertSame(200, $detailPage['http_status']);
    assertTrue(strpos($detailPage['html'], 'data-mode="full"') !== false, 'Detail page must render full refund button.');
    assertTrue(strpos($detailPage['html'], 'data-mode="overpayment"') !== false, 'Detail page must render overpayment refund button.');

    // R3.1: observeExternalRefund() when Transactions::edit fails must NOT set note_id and MUST record review_reason
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_641_r0',
        'paymos_invoice_id' => 'inv_r3_641',
        'platform_ref' => 'inv_641',
        'blesta_invoice_id' => 641,
        'client_id' => '81',
        'amount' => '55',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_r3_641',
        'transaction_id' => '0xtx641',
        'status' => 'paid',
        'final_status' => 'paid',
        'created_at' => $now - 200,
    ));
    $failingNative = function (string $cmd, array $input) use ($snapshots): array {
        if ($cmd === 'snapshot') {
            return array(
                'invoice' => array('id' => 641, 'client_id' => '81', 'status' => 'active', 'total' => '55', 'currency' => 'USD'),
                'transaction' => array('id' => '9641', 'client_id' => '81', 'transaction_id' => '0xtx641', 'reference_id' => 'inv_r3_641', 'amount' => '55', 'currency' => 'USD', 'status' => 'approved', 'notes' => ''),
                'mapping' => $snapshots->findPaidByBlestaInvoiceId(641),
            );
        }
        if ($cmd === 'Transactions::edit') {
            return array('result' => 'error', 'errors' => array('status' => 'db_write_failed'));
        }
        return array();
    };
    $r3Transport = new SpyTransport(function (array $req): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($path === '/v1/refunds') {
            return jsonResponse(200, array('items' => array(
                array('withdrawal_id' => 'wd_r3_641', 'invoice_id' => 'inv_r3_641', 'project_id' => 'prj_sandbox_blesta_001', 'is_test' => true, 'mode' => 'full', 'external_order_id' => 'dash_641', 'status' => 'completed'),
            ), 'next_cursor' => null));
        }
        if ($path === '/v1/refunds/wd_r3_641') {
            return jsonResponse(200, array('withdrawal_id' => 'wd_r3_641', 'invoice_id' => 'inv_r3_641', 'project_id' => 'prj_sandbox_blesta_001', 'is_test' => true, 'mode' => 'full', 'external_order_id' => 'dash_641', 'status' => 'completed', 'amount' => '55', 'currency' => 'USDT', 'network' => 'arbitrum', 'first_sender' => '0x641'));
        }
        if ($path === '/v1/invoices/inv_r3_641') {
            return jsonResponse(200, array('invoice_id' => 'inv_r3_641', 'project_id' => 'prj_sandbox_blesta_001', 'status' => 'paid', 'is_test' => true, 'order' => array('external_id' => 'blesta_inv_641_r0', 'amount' => '55', 'currency' => 'USD')));
        }
        throw new \RuntimeException('Unexpected: ' . $path);
    });
    $failAdapter = new RefundAdapter($config, $credStore, $snapshots, $intentStore, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $r3Transport), $failingNative, null, $clock);
    $failAdapter->discoverExternal('sandbox');
    $ext641 = $intentStore->externalForPayment('641');
    assertSame(1, count($ext641));
    assertSame(null, $ext641[0]['note_id'], 'Failed Transactions::edit MUST NOT set note_id.');
    assertSame('refund_native_projection_failed', $ext641[0]['review_reason']);

    // R3.2: observeExternalRefund() on already-refunded transaction (manual conflict, no marker) must NOT call Transactions::edit
    $snapshots->save(array(
        'external_order_id' => 'blesta_inv_642_r0',
        'paymos_invoice_id' => 'inv_r3_642',
        'platform_ref' => 'inv_642',
        'blesta_invoice_id' => 642,
        'client_id' => '82',
        'amount' => '65',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_r3_642',
        'transaction_id' => '0xtx642',
        'status' => 'paid',
        'final_status' => 'paid',
        'created_at' => $now - 200,
    ));
    $conflictEdits = 0;
    $conflictNative = function (string $cmd, array $input) use ($snapshots, &$conflictEdits): array {
        if ($cmd === 'snapshot') {
            return array(
                'invoice' => array('id' => 642, 'client_id' => '82', 'status' => 'active', 'total' => '65', 'currency' => 'USD'),
                'transaction' => array('id' => '9642', 'client_id' => '82', 'transaction_id' => '0xtx642', 'reference_id' => 'inv_r3_642', 'amount' => '65', 'currency' => 'USD', 'status' => 'refunded', 'notes' => 'Manually refunded earlier'),
                'mapping' => $snapshots->findPaidByBlestaInvoiceId(642),
            );
        }
        if ($cmd === 'Transactions::edit') {
            $conflictEdits++;
            return array('result' => 'success');
        }
        return array();
    };
    $r3ConflictTransport = new SpyTransport(function (array $req): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($path === '/v1/refunds') {
            return jsonResponse(200, array('items' => array(
                array('withdrawal_id' => 'wd_r3_642', 'invoice_id' => 'inv_r3_642', 'project_id' => 'prj_sandbox_blesta_001', 'is_test' => true, 'mode' => 'full', 'external_order_id' => 'dash_642', 'status' => 'completed'),
            ), 'next_cursor' => null));
        }
        if ($path === '/v1/refunds/wd_r3_642') {
            return jsonResponse(200, array('withdrawal_id' => 'wd_r3_642', 'invoice_id' => 'inv_r3_642', 'project_id' => 'prj_sandbox_blesta_001', 'is_test' => true, 'mode' => 'full', 'external_order_id' => 'dash_642', 'status' => 'completed', 'amount' => '65', 'currency' => 'USDT', 'network' => 'arbitrum', 'first_sender' => '0x642'));
        }
        if ($path === '/v1/invoices/inv_r3_642') {
            return jsonResponse(200, array('invoice_id' => 'inv_r3_642', 'project_id' => 'prj_sandbox_blesta_001', 'status' => 'paid', 'is_test' => true, 'order' => array('external_id' => 'blesta_inv_642_r0', 'amount' => '65', 'currency' => 'USD')));
        }
        throw new \RuntimeException('Unexpected: ' . $path);
    });
    $conflictAdapter = new RefundAdapter($config, $credStore, $snapshots, $intentStore, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $r3ConflictTransport), $conflictNative, null, $clock);
    $conflictAdapter->discoverExternal('sandbox');
    $ext642 = $intentStore->externalForPayment('642');
    assertSame(0, $conflictEdits, 'Already-refunded transaction without matching marker must NOT be clobbered.');
    assertSame(null, $ext642[0]['note_id']);
    assertSame('refund_original_full_paid_required', $ext642[0]['review_reason']);
});

// ── Group 7: 6 Native Locales, ISO 4217 Manifest, Docs Zero-Leak & PHP Lint ─

runTest('7.1 All 6 Blesta locales have full key parity, real native translations, and zero manual secret keys', function () {
    Translation::resetCache();
    $en = Translation::catalog('en_us');
    assertTrue(count($en) >= 40, 'Expected comprehensive en_us catalog.');
    $enKeys = array_keys($en);
    sort($enKeys);

    foreach (Translation::LOCALES as $locale) {
        $cat = Translation::catalog($locale);
        $keys = array_keys($cat);
        sort($keys);
        assertSame($enKeys, $keys, "Key mismatch in locale {$locale}");

        $loanwords = array('Paymos.name', 'Paymos.connect.code', 'Paymos.mode.sandbox', 'Paymos.mode.live');
        $identicalToEn = 0;
        foreach ($cat as $k => $v) {
            assertTrue(trim($v) !== '', "Empty string for {$k} in {$locale}");
            assertTrue(stripos($v, 'instant settlement') === false, "Forbidden phrase in {$locale}:{$k}");
            if ($locale !== 'en_us' && !in_array($k, $loanwords, true) && $v === $en[$k]) {
                $identicalToEn++;
            }
        }
        if ($locale !== 'en_us') {
            assertSame(0, $identicalToEn, "Locale {$locale} has {$identicalToEn} untranslated English strings.");
        }
    }

    assertSame('ru_ru', Translation::normalizeLocale('ru'));
    assertSame('zh_cn', Translation::normalizeLocale('zh-Hans'));
    assertSame('zh', Translation::sdkLocale('zh_cn'));
});

runTest('7.2 config.json ISO 4217 currencies, canonical README/CHANGELOG/LICENSE, zero-leak, and legacy deletions', function () use ($pluginRoot, $gatewayRoot) {
    $cfg = json_decode((string) file_get_contents($gatewayRoot . DIRECTORY_SEPARATOR . 'config.json'), true);
    assertTrue(preg_match('/^\d+\.\d+\.\d+$/', (string) ($cfg['version'] ?? '')) === 1, 'config.json version must be valid SemVer.');
    assertTrue(count($cfg['currencies']) >= 15);
    foreach ($cfg['currencies'] as $curr) {
        assertTrue(preg_match('/\A[A-Z]{3}\z/', (string) $curr) === 1, "Invalid currency code: {$curr}");
        assertTrue($curr !== 'DAI', 'Crypto ticker DAI must not appear in ISO 4217 currencies.');
    }

    $readme = (string) file_get_contents($pluginRoot . DIRECTORY_SEPARATOR . 'README.md');
    $changelog = (string) file_get_contents($pluginRoot . DIRECTORY_SEPARATOR . 'CHANGELOG.md');
    $license = (string) file_get_contents($pluginRoot . DIRECTORY_SEPARATOR . 'LICENSE');

    assertTrue(preg_match('/^- PHP 8\.1\+/m', $readme) === 1, 'README must state - PHP 8.1+.');
    assertTrue(strpos($readme, 'https://github.com/Paymos-labs/blesta') !== false, 'README must link Paymos-labs/blesta.');
    assertTrue(strpos($readme, '/callback/gw/1/paymos/') !== false && strpos($readme, 'company_id = 1') !== false, 'README must document company_id = 1 callback path.');
    assertTrue(strpos($changelog, '## [Unreleased]') !== false, 'CHANGELOG must include ## [Unreleased].');
    assertTrue(strpos($license, 'MIT License') !== false && strpos($license, 'Paymos Labs') !== false, 'LICENSE must be MIT for Paymos Labs.');

    assertTrue(preg_match('/github\.com\/(?!Paymos-labs\/)/i', $readme) !== 1, 'README must reference only Paymos-labs GitHub URLs.');
    assertTrue(preg_match('/github\.com\/(?!Paymos-labs\/)/i', $changelog) !== 1, 'CHANGELOG must reference only Paymos-labs GitHub URLs.');
    assertTrue(stripos($readme, 'instant settlement') === false, 'README must not claim instant settlement.');
    assertTrue(stripos($changelog, 'instant settlement') === false, 'CHANGELOG must not claim instant settlement.');

    assertTrue(!file_exists($gatewayRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'GatewayService.php'), 'Legacy GatewayService.php must be deleted.');
    assertTrue(!file_exists($pluginRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'package.py'), 'Legacy scripts/package.py must be deleted.');
    assertTrue(!file_exists($pluginRoot . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'paymos-blesta-v1.0.0.zip'), 'Legacy dist ZIP must be deleted.');
});

runTest('7.3 PHP syntax lint (php -l) passes across all Blesta PHP and PDT files', function () use ($pluginRoot) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginRoot));
    $checked = 0;
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if ($ext !== 'php' && $ext !== 'pdt') {
            continue;
        }
        $path = $file->getPathname();
        $output = array();
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException("php -l failed for {$path}: " . implode("\n", $output));
        }
        $checked++;
    }
    assertTrue($checked >= 25, "Expected at least 25 PHP/PDT files linted, got {$checked}");
});

runTest('7.4 Blesta 6.0.5 live compatibility: non-static Form CSRF, transactions.message marker, logos, and AdminRefunds MVC', function () use ($pluginRoot, $gatewayRoot, $companionRoot) {
    // #B-3: Both gateway and companion plugin bundle light/dark SVG + PNG logos and declare Blesta 6.0 config.json logo/icon
    foreach (array($gatewayRoot, $companionRoot) as $dir) {
        $cfg = json_decode((string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'config.json'), true);
        assertSame('views/default/images/logo.svg', $cfg['logo']['light'] ?? null, 'Missing light logo in ' . $dir);
        assertSame('views/default/images/logo-dark.svg', $cfg['logo']['dark'] ?? null, 'Missing dark logo in ' . $dir);
        foreach (array('logo.svg', 'logo-dark.svg', 'logo.png') as $img) {
            $imgPath = $dir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $img;
            assertTrue(is_file($imgPath) && filesize($imgPath) > 100, 'Missing or empty brand asset: ' . $imgPath);
        }
    }
    $pluginCfg = json_decode((string) file_get_contents($companionRoot . DIRECTORY_SEPARATOR . 'config.json'), true);
    assertSame('bi bi-currency-exchange', $pluginCfg['icon'] ?? null, 'Companion plugin config.json must declare Bootstrap icon.');

    // #B-4: admin_refunds.pdt exists, positional $this->get[0] works, JSON actions return false
    $pdtPath = $companionRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'admin_refunds.pdt';
    assertTrue(is_file($pdtPath), 'plugins/paymos/views/default/admin_refunds.pdt must exist.');

    $db = new InMemoryDbDriver();
    $credStore74 = new CredentialStore($db, static fn (): string => Configure::get('Blesta.system_key'));
    $credStore74->saveCredentials(array(
        'sandbox' => array(
            'project_id' => 'prj_blesta_74',
            'api_key' => 'pk_test_blesta_74_00001',
            'api_secret' => 'sk_test_blesta_74_00001',
            'webhook_secret' => 'whsec_blesta_74_0000001',
            'base_url' => 'https://api.paymos.io',
            'capabilities' => array('invoice_refunds_v1'),
            'refund_contract_version' => 1,
        ),
    ));
    $ctrl = new AdminRefunds();
    $ctrl->setDbDriver($db);
    $ctrl->setStaffAuthenticator(static fn (): bool => true);
    $ctrl->get = array(0 => '42');
    $capturedVars = array();
    $mvcCtrl = new class($capturedVars) extends AdminRefunds {
        public array $vars;
        public function __construct(array &$vars) { $this->vars = &$vars; }
        public function set(string $key, $value): void { $this->vars[$key] = $value; }
    };
    $mvcCtrl->setDbDriver($db);
    $mvcCtrl->setStaffAuthenticator(static fn (): bool => true);
    $mvcCtrl->get = array(0 => '42');
    $mvcCtrl->view = (object) array('view' => 'paradigm');
    $mvcCtrl->structure = new class {
        public string $view = 'paradigm';
        public ?string $defaultView = null;
        public ?string $restoredView = null;
        public function setDefaultView(string $path): void { $this->defaultView = $path; }
        public function setView($view, string $path): void { $this->restoredView = $path; }
    };
    $mvcCtrl->preAction();
    assertSame('default', $mvcCtrl->view->view, 'PaymosController::preAction() must set plugin view theme to default for Blesta 6.0 Paradigm.');
    assertSame('paradigm', $mvcCtrl->structure->restoredView, 'PaymosController::preAction() must preserve Blesta admin structure view.');
    $mvcCtrl->index();
    assertTrue(isset($capturedVars['content']) && strpos($capturedVars['content'], 'value="42"') !== false, 'AdminRefunds::index() must resolve $this->get[0] and pass HTML to Blesta view via set().');

    ob_start();
    $panelRet = $ctrl->panel();
    $panelJson = (string) ob_get_clean();
    assertSame(false, $panelRet, 'AdminRefunds::panel() must return false to suppress Blesta view rendering.');
    assertTrue(strpos($panelJson, '"invoice_id":42') !== false, 'AdminRefunds::panel() must read positional $this->get[0]. Got: ' . $panelJson);

    $connectCtrl = new AdminConnect();
    $connectCtrl->setDbDriver($db);
    $connectCtrl->setStaffAuthenticator(static fn (): bool => false);
    ob_start();
    $connectRet = $connectCtrl->start();
    ob_end_clean();
    assertSame(false, $connectRet, 'AdminConnect::start() must return false to suppress Blesta view rendering.');

    // #B-1 & #B-2: Isolated subprocess testing real Minphp\Form non-static 2-arg CSRF + Blesta transactions.message column
    $probe = <<<'PHP'
class Form {
    public function getCsrfToken($key = null) { return 'instance-csrf-token-605'; }
    public function verifyCsrfToken($key = null, $csrf_token = null) {
        return is_string($csrf_token) && hash_equals('instance-csrf-token-605', $csrf_token);
    }
}
class AppController {
    public $Form;
    public $get = [];
    public function __construct() { $this->Form = new Form(); }
    public function preAction() {}
}
class Loader {
    public static array $txStore = [];
    public static array $invStore = [];
    public static function loadModels(object $host, array $models): void {
        if (in_array('Invoices', $models, true)) {
            $host->Invoices = new class {
                public function get(int $id) { return Loader::$invStore[$id] ?? null; }
            };
        }
        if (in_array('Transactions', $models, true)) {
            $host->Transactions = new class {
                public function get(int $id) { return Loader::$txStore[$id] ?? null; }
                public function getByTransactionId(string $txid, ?int $clientId = null) {
                    foreach (Loader::$txStore as $t) {
                        if ($t->transaction_id === $txid && ($clientId === null || (int) $t->client_id === $clientId)) return $t;
                    }
                    return null;
                }
                public function getList(?int $clientId = null, ?string $status = 'approved'): array {
                    $out = [];
                    foreach (Loader::$txStore as $t) {
                        if ($clientId !== null && (int) $t->client_id !== $clientId) continue;
                        if ($status !== null && (string) $t->status !== $status) continue;
                        $out[] = $t;
                    }
                    return $out;
                }
                public function edit(int $id, array $vars): void {
                    // Simulate real Blesta 6.0.5 Transactions::edit() which ONLY updates whitelisted columns (message, NOT notes!)
                    $t = Loader::$txStore[$id];
                    if (isset($vars['status'])) $t->status = $vars['status'];
                    if (isset($vars['message'])) $t->message = substr((string) $vars['message'], 0, 255);
                }
                public function errors() { return null; }
            };
        }
    }
}
$pluginRoot = $argv[1];
require_once $pluginRoot . '/plugins/paymos/controllers/admin_connect.php';
$ac = new AdminConnect();
$ac->setStaffAuthenticator(static fn (): bool => true);
$resOk = $ac->handleRequest('POST', ['action' => 'invalid', '_csrf_token' => 'instance-csrf-token-605']);
$resBad = $ac->handleRequest('POST', ['action' => 'invalid', '_csrf_token' => 'wrong-token']);
if ($resOk['http_status'] !== 400 || $resBad['http_status'] !== 403) {
    echo 'CSRF_FAIL:' . json_encode([$resOk, $resBad]);
    exit(1);
}

// Test Transactions::edit() writing 'message' and snapshot/marker still finding the refunded transaction
// under Blesta 6.0.5's real Transactions::getByTransactionId() / getList($clientId, $status = 'approved') API.
Loader::$invStore[5] = (object) ['id' => 5, 'client_id' => 9, 'status' => 'active', 'total' => '17.00', 'currency' => 'USD'];
Loader::$txStore[10] = (object) ['id' => 10, 'client_id' => 9, 'transaction_id' => '0xtx_5', 'reference_id' => 'inv_b605', 'amount' => '17.00', 'currency' => 'USD', 'status' => 'approved', 'message' => ''];

$memDb = new class implements \PaymosBlesta\DbDriverInterface {
    private array $settings = [];
    private array $snapshots = [];
    public function driverName(): string { return 'memory'; }
    public function inTransaction(): bool { return false; }
    public function tableExists(string $table): bool { return true; }
    public function execute(string $sql, array $params = []): int {
        if (str_contains($sql, 'UPDATE') && count($params) >= 3) {
            $this->settings[(string) $params[2]] = (string) $params[0];
        }
        return 1;
    }
    public function fetchOne(string $sql, array $params = []): ?array {
        if (str_contains($sql, 'paymos_invoice_snapshots')) {
            return $this->snapshots[0] ?? null;
        }
        $k = (string) ($params[0] ?? '');
        return isset($this->settings[$k]) ? ['setting_value' => $this->settings[$k]] : null;
    }
    public function fetchAll(string $sql, array $params = []): array {
        if (str_contains($sql, 'paymos_invoice_snapshots')) {
            return $this->snapshots;
        }
        return [];
    }
    public function insertUnique(string $table, array $columns, ?string $conflictColumn = null): bool {
        if ($table === 'paymos_invoice_snapshots') {
            $this->snapshots = [$columns];
            return true;
        }
        if (isset($columns['setting_key'], $columns['setting_value'])) {
            $k = (string) $columns['setting_key'];
            if (isset($this->settings[$k])) return false;
            $this->settings[$k] = (string) $columns['setting_value'];
        }
        return true;
    }
    public function casUpdate(string $table, array $set, array $where, array $conditions = []): int { return 1; }
    public function deleteExpired(string $table, string $column, int $now, int $limit = 100): int { return 0; }
    public function beginTransaction(): void {}
    public function commit(): bool { return true; }
    public function rollBack(): void {}
    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool { return true; }
    public function isNamedLockOwned(string $name): bool { return true; }
    public function releaseNamedLock(string $name): void {}
};
$credStore = new \PaymosBlesta\CredentialStore($memDb, static fn (): string => 'blesta-605-system-key-32-chars!!', static fn (): int => 1795640000);
$credStore->saveCredentials([
    'sandbox' => [
        'project_id' => 'prj_b605',
        'api_key' => 'pk_test_b605_000000001',
        'api_secret' => 'sk_test_b605_000000001',
        'webhook_secret' => 'whsec_b605_00000000001',
        'base_url' => 'https://api.paymos.io',
        'capabilities' => ['invoice_refunds_v1'],
        'refund_contract_version' => 1,
    ],
]);
$cfg = \PaymosBlesta\Config::fromStore(['mode' => 'sandbox'], $credStore);
$snaps = new \PaymosBlesta\SnapshotStore($memDb, static fn (): int => 1795640000);
$snaps->save([
    'external_order_id' => 'blesta_inv_5_r0',
    'paymos_invoice_id' => 'inv_b605',
    'platform_ref' => 'inv_5',
    'blesta_invoice_id' => 5,
    'client_id' => '9',
    'amount' => '17.00',
    'currency' => 'USD',
    'environment' => 'sandbox',
    'project_id' => 'prj_b605',
    'payment_url' => 'https://checkout.paymos.io/inv/inv_b605',
    'transaction_id' => '0xtx_5',
    'status' => 'paid',
]);
$intents = new \PaymosBlesta\RefundIntentStore($memDb, $credStore, static fn (): int => 1795640000);
$adapter = new \PaymosBlesta\RefundAdapter($cfg, $credStore, $snaps, $intents, null, null, null, static fn (): int => 1795640000);
$callNative = new \ReflectionMethod($adapter, 'callNative');
$callNative->invoke($adapter, 'Transactions::edit', [
    'transaction_id' => 10,
    'invoice_id' => 5,
    'vars' => [
        'status' => 'refunded',
        'reference_id' => 'inv_b605',
        'transaction_id' => '0xtx_5',
        'message' => 'Paymos full refund completed [paymos_refund_wdr_605] (17 USDT/TRON)',
        'notes' => 'ignored by real Blesta',
    ],
]);
// Post-edit snapshot MUST still locate the refunded transaction via getByTransactionId() / getList($clientId, null)
$snapAfter = $callNative->invoke($adapter, 'snapshot', ['invoice_id' => 5]);
$markers = $callNative->invoke($adapter, 'marker', ['transaction_id' => 10, 'marker' => 'paymos_refund_wdr_605']);
$ok = ($snapAfter['transaction']['status'] ?? '') === 'refunded'
    && ($snapAfter['transaction']['id'] ?? '') === '10'
    && count($markers) === 1
    && ($markers[0]['status'] ?? '') === 'refunded'
    && str_contains((string) ($markers[0]['message'] ?? ''), 'paymos_refund_wdr_605');
echo $ok ? 'OK' : ('MARKER_FAIL:' . json_encode([$snapAfter, $markers, Loader::$txStore[10]]));
exit($ok ? 0 : 1);
PHP;
    $tmp = tempnam(sys_get_temp_dir(), 'blesta605_probe_');
    file_put_contents($tmp, "<?php\n" . $probe);
    $out = array();
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($pluginRoot) . ' 2>&1', $out, $code);
    @unlink($tmp);
    assertSame(0, $code, 'Blesta 6.0.5 subprocess probe failed: ' . implode("\n", $out));
    assertSame('OK', trim(implode('', $out)));
});

// ── Group 8: Audit fixes 2026-11-28 (CMSP-05..18, 33..37) ───────────────────

final class FakeCronTasks
{
    /** @var array<int, object> */
    public $tasks = array();
    /** @var array<int, array<string, mixed>> */
    public $runs = array();
    /** @var array<int, array<string, mixed>> */
    public $addCalls = array();
    /** @var array<int, array<int, mixed>> */
    public $runCalls = array();
    /** @var array<int, int> */
    public $deletedRuns = array();
    /** @var array<int, array<int, mixed>> */
    public $deletedTasks = array();
    /** @var array<int, array<int, mixed>> */
    public $edits = array();
    /** @var mixed */
    public $errors = null;
    /** @var bool */
    public $failAdd = false;
    /** @var int */
    private $nextTask = 100;
    /** @var int */
    private $nextRun = 500;

    /** @return int|null */
    public function add(array $vars)
    {
        $this->addCalls[] = $vars;
        $this->errors = null;
        if ($this->failAdd || empty($vars['key']) || empty($vars['name'])
            || !in_array($vars['task_type'] ?? '', array('plugin', 'module', 'system'), true)) {
            $this->errors = array('key' => array('invalid' => 'Cron task is invalid.'));
            return null;
        }
        if ($this->getByKey($vars['key'], $vars['dir'] ?? null, $vars['task_type']) !== false) {
            $this->errors = array('key' => array('unique' => 'Cron task key already exists.'));
            return null;
        }
        $id = $this->nextTask++;
        $this->tasks[$id] = (object) array(
            'id' => $id,
            'key' => $vars['key'],
            'task_type' => $vars['task_type'],
            'dir' => $vars['dir'] ?? null,
            'name' => $vars['name'],
            'description' => $vars['description'] ?? '',
            'is_lang' => (string) ($vars['is_lang'] ?? '0'),
            'type' => $vars['type'] ?? 'interval',
        );
        return $id;
    }

    public function seedLegacyTask(): int
    {
        $id = $this->nextTask++;
        $this->tasks[$id] = (object) array(
            'id' => $id,
            'key' => PaymosPlugin::CRON_KEY,
            'task_type' => 'plugin',
            'dir' => 'paymos',
            'name' => 'PaymosPlugin.cron.reconcile_name',
            'description' => 'PaymosPlugin.cron.reconcile_desc',
            'is_lang' => '0',
            'type' => 'interval',
        );
        return $id;
    }

    public function edit($taskId, array $vars): void
    {
        $this->edits[] = array($taskId, $vars);
        foreach ($vars as $k => $v) {
            $this->tasks[$taskId]->{$k} = (string) $v;
        }
    }

    /** @return object|false */
    public function getByKey($key, $dir = null, $type = 'plugin')
    {
        foreach ($this->tasks as $task) {
            if ($task->key === $key && $task->dir === $dir && $task->task_type === $type) {
                return clone $task;
            }
        }
        return false;
    }

    /** @return int */
    public function addTaskRun($taskId, array $vars)
    {
        $this->runCalls[] = array($taskId, $vars);
        $id = $this->nextRun++;
        $this->runs[$id] = array('task_id' => $taskId) + $vars;
        return $id;
    }

    /** @return object|false */
    public function getTaskRunByKey($key, $dir = null, $system = false, $type = 'plugin')
    {
        $task = $this->getByKey($key, $dir, $type);
        if ($task === false) {
            return false;
        }
        foreach ($this->runs as $id => $run) {
            if ($run['task_id'] === $task->id) {
                return (object) array('task_run_id' => $id, 'task_id' => $task->id, 'key' => $key);
            }
        }
        return false;
    }

    public function deleteTaskRun($taskRunId): void
    {
        $this->deletedRuns[] = $taskRunId;
        unset($this->runs[$taskRunId]);
    }

    public function deleteTask($taskId, $taskType, $dir): void
    {
        $this->deletedTasks[] = array($taskId, $taskType, $dir);
        unset($this->tasks[$taskId]);
        foreach ($this->runs as $runId => $run) {
            if ($run['task_id'] === $taskId) {
                unset($this->runs[$runId]);
            }
        }
    }

    /** @return mixed */
    public function errors()
    {
        return $this->errors;
    }
}

/**
 * Wraps the in-memory driver and makes the next webhook-dedup commit fail (a lost write).
 */
final class CommitFailingDb implements DbDriverInterface
{
    /** @var InMemoryDbDriver */
    private $inner;
    /** @var bool */
    public $failNextEventCommit = false;

    public function __construct(InMemoryDbDriver $inner)
    {
        $this->inner = $inner;
    }

    public function driverName(): string { return $this->inner->driverName(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function tableExists(string $table): bool { return $this->inner->tableExists($table); }
    public function execute(string $sql, array $params = array()): int { return $this->inner->execute($sql, $params); }
    public function fetchOne(string $sql, array $params = array()): ?array { return $this->inner->fetchOne($sql, $params); }
    public function fetchAll(string $sql, array $params = array()): array { return $this->inner->fetchAll($sql, $params); }
    public function insertUnique(string $table, array $columns, ?string $conflictColumn = null): bool { return $this->inner->insertUnique($table, $columns, $conflictColumn); }
    public function casUpdate(string $table, array $set, array $where, array $conditions = array()): int
    {
        if ($table === PdoEventStore::TABLE && $this->failNextEventCommit && ($set['state'] ?? '') === 'committed') {
            $this->failNextEventCommit = false;
            return 0;
        }
        return $this->inner->casUpdate($table, $set, $where, $conditions);
    }
    public function deleteExpired(string $table, string $column, int $now, int $limit = 100): int { return $this->inner->deleteExpired($table, $column, $now, $limit); }
    public function beginTransaction(): void { $this->inner->beginTransaction(); }
    public function commit(): bool { return $this->inner->commit(); }
    public function rollBack(): void { $this->inner->rollBack(); }
    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool { return $this->inner->acquireNamedLock($name, $timeoutSeconds); }
    public function isNamedLockOwned(string $name): bool { return $this->inner->isNamedLockOwned($name); }
    public function releaseNamedLock(string $name): void { $this->inner->releaseNamedLock($name); }
}

/**
 * @param array<string, mixed> $overrides
 */
function seedSnapshot(SnapshotStore $snapshots, array $overrides): void
{
    $snapshots->save(array_merge(array(
        'external_order_id' => 'blesta_inv_0_r0',
        'paymos_invoice_id' => 'inv_0',
        'platform_ref' => 'inv_0',
        'blesta_invoice_id' => null,
        'client_id' => '9',
        'amount' => '10',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_blesta_001',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_0',
        'status' => 'awaiting_payment',
        'created_at' => 1764000000,
    ), $overrides));
}

/**
 * @param array<string, mixed> $invoice
 */
function invoiceWebhook(string $eventId, string $eventType, array $invoice, int $occurredAt): string
{
    return (string) json_encode(array(
        'event_id' => $eventId,
        'event_type' => $eventType,
        'version' => 1,
        'occurred_at' => $occurredAt,
        'data' => $invoice,
    ));
}

/**
 * @return array<string, mixed>
 */
function sandboxInvoice(string $invoiceId, string $externalId, string $status, string $amount, array $extra = array()): array
{
    return array_merge(array(
        'invoice_id' => $invoiceId,
        'project_id' => 'prj_sandbox_blesta_001',
        'status' => $status,
        'is_test' => true,
        'order' => array('external_id' => $externalId, 'amount' => $amount, 'currency' => 'USD'),
    ), $extra);
}

runTest('8.1 CMSP-05: cron task registered via CronTasks API (task_type/dir/is_lang), uninstall removes the company task run', function () {
    $prev = Loader::$models;
    $cron = new FakeCronTasks();
    Loader::$models['CronTasks'] = $cron;
    try {
        $db = new InMemoryDbDriver();
        $plugin = new PaymosPlugin();
        $plugin->setDbDriver($db);
        $plugin->install(7);

        assertSame(1, count($cron->addCalls), 'CronTasks::add must be called once.');
        $vars = $cron->addCalls[0];
        assertSame(PaymosPlugin::CRON_KEY, $vars['key']);
        assertSame('plugin', $vars['task_type'] ?? null, 'task_type must be plugin.');
        assertSame('paymos', $vars['dir'] ?? null, 'dir must be the plugin directory.');
        assertSame(1, $vars['is_lang'] ?? null, 'Name/description are language keys: is_lang must be 1.');
        assertTrue(!array_key_exists('plugin_dir', $vars), 'Legacy plugin_dir must not be sent.');
        assertSame(1, count($cron->runs), 'A 10-minute task run must be created for the company.');
        $run = array_values($cron->runs)[0];
        assertSame(10, $run['interval']);
        assertSame(1, $run['enabled']);

        $runId = (int) array_keys($cron->runs)[0];
        $plugin->uninstall(7, false);
        assertSame(array($runId), $cron->deletedRuns, 'Uninstall must delete this company task run.');
        assertSame(array(), $cron->deletedTasks, 'A non-last instance keeps the global task definition.');

        // Legacy install: raw-key task with is_lang=0 and no run -> upgrade repairs it once.
        $legacy = new FakeCronTasks();
        Loader::$models['CronTasks'] = $legacy;
        $legacyId = $legacy->seedLegacyTask();
        $plugin->upgrade('1.0.4', 7);
        $plugin->upgrade('1.0.4', 7);
        assertSame('1', $legacy->tasks[$legacyId]->is_lang, 'Upgrade must switch the task name to a language definition.');
        assertSame(1, count($legacy->runs), 'Upgrade must ensure exactly one task run.');
        $plugin->uninstall(7, true);
        assertSame(array(array($legacyId, 'plugin', 'paymos')), $legacy->deletedTasks, 'Last instance must delete the task definition.');

        // add() rejected and nothing to fall back to -> warning logged with Blesta errors.
        $broken = new FakeCronTasks();
        $broken->failAdd = true;
        Loader::$models['CronTasks'] = $broken;
        $plugin2 = new PaymosPlugin();
        $plugin2->setDbDriver(new InMemoryDbDriver());
        $plugin2->install(8);
        $logged = array_filter($plugin2->getLogs(), static fn (array $l): bool => $l['event'] === 'plugin.cron_register_error');
        assertSame(1, count($logged), 'A rejected CronTasks::add must be logged.');
        assertSame(0, count($broken->runs));
    } finally {
        Loader::$models = $prev;
    }
});

runTest('8.2 CMSP-06: Connect and Refunds are staff permissions; requireLogin() denies a group without them; upgrade keeps the upgrading admin', function () {
    $plugin = new PaymosPlugin();
    $groups = $plugin->getPermissionGroups();
    assertSame(1, count($groups));
    assertSame('staff', $groups[0]['level']);
    $groupAlias = $groups[0]['alias'];
    $aliases = array();
    foreach ($plugin->getPermissions() as $permission) {
        assertSame($groupAlias, $permission['group_alias']);
        assertSame('*', $permission['action']);
        assertTrue(trim((string) $permission['name']) !== '');
        $aliases[] = $permission['alias'];
    }
    sort($aliases);
    assertSame(array('paymos.admin_connect', 'paymos.admin_refunds'), $aliases);

    // Fresh install: Blesta grants the installer's group, the plugin records that permissions are live.
    $db = new InMemoryDbDriver();
    $installer = new PaymosPlugin();
    $installer->setDbDriver($db);
    $installer->install(13);
    assertSame(true, StaffPermissions::isGranted(new CredentialStore($db)), 'Install marks the staff permissions as granted.');

    AppController::$deniedAcos = array('paymos.admin_connect', 'paymos.admin_refunds');
    try {
        foreach (array('admin_connect' => new AdminRefunds(), 'admin_refunds' => new AdminRefunds()) as $name => $ctrl) {
            $ctrl->setDbDriver($db);
            $ctrl->plugin = 'paymos';
            $ctrl->controller = $name;
            $ctrl->action = 'index';
            assertThrows(\RuntimeException::class, static fn () => $ctrl->preAction(), 'blesta_acl_denied:paymos.' . $name);
        }
    } finally {
        AppController::$deniedAcos = array();
    }
    $allowed = new AdminRefunds();
    $allowed->setDbDriver($db);
    $allowed->plugin = 'paymos';
    $allowed->controller = 'admin_refunds';
    $allowed->action = 'index';
    $allowed->preAction();
    assertSame(1, $allowed->requireLoginCalls, 'preAction must call requireLogin() exactly once.');
});

runTest('8.3 CMSP-07: partial and "Other Payment Amount" payments are recorded with Blesta per-invoice amounts', function () {
    $db = new InMemoryDbDriver();
    $now = 1764100000;
    $clock = static fn (): int => $now;
    (new CredentialStore($db, null, $clock))->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $prev = Loader::$models;
    $due = '100.00';
    Loader::$models['Invoices'] = new class($due) {
        public $due;
        public function __construct(&$due) { $this->due = &$due; }
        public function get(int $id) {
            return (object) array('id' => $id, 'client_id' => 9, 'status' => 'active', 'date_closed' => null, 'total' => $this->due, 'due' => $this->due, 'currency' => 'USD');
        }
    };
    $created = array();
    $transport = new SpyTransport(function (array $req) use (&$created): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($req['method'] === 'POST' && $path === '/v1/invoices') {
            $body = json_decode((string) $req['body'], true);
            $id = 'inv_part_' . (count($created) + 1);
            $created[$id] = $body;
            return jsonResponse(201, array('invoice_id' => $id, 'payment_url' => 'https://checkout.paymos.io/inv/' . $id, 'status' => 'awaiting_client'));
        }
        if ($req['method'] === 'GET' && strncmp($path, '/v1/invoices/', 13) === 0) {
            $id = substr($path, 13);
            $body = $created[$id];
            return jsonResponse(200, sandboxInvoice($id, $body['external_order_id'], 'paid', $body['amount'], array('payment' => array('tx_hash' => '0x' . $id))));
        }
        throw new \RuntimeException('Unexpected request: ' . $req['method'] . ' ' . $path);
    });

    try {
        $cases = array(
            array('801', '40.00', array(array('id' => 801, 'amount' => '40.00')), '40', array(array('id' => 801, 'amount' => '40'))),
            array('802', '120.00', array(array('id' => 802, 'amount' => '100.00')), '120', array(array('id' => 802, 'amount' => '100'))),
        );
        foreach ($cases as $i => $case) {
            list($label, $total, $invoiceAmounts, $expectedAmount, $expectedInvoices) = $case;
            $gw = makeGateway($db, $transport, $clock);
            $html = $gw->buildProcess(array('client_id' => '9'), $total, $invoiceAmounts);
            assertTrue($html !== '', 'Checkout must render for invoice ' . $label);
            $paymosId = 'inv_part_' . ($i + 1);
            $snapshot = $snapshots->findByInvoiceId($paymosId);
            $body = invoiceWebhook('evt_part_' . $label, 'invoice.paid', sandboxInvoice($paymosId, $snapshot['external_order_id'], 'paid', $expectedAmount), $now);
            $sig = signWebhook($body, 'whsec_sandbox_blesta_secret_001', $now);
            $gw->setWebhookInputProvider(static fn (): string => $body, static fn (): array => array('X-Webhook-Signature' => $sig));
            $tx = $gw->validate(array(), array());
            assertSame(200, $gw->getLastHttpStatus(), 'Invoice ' . $label . ' payment must be accepted: ' . json_encode($gw->getLastWebhookResult()));
            assertSame($expectedAmount, $tx['amount']);
            assertSame($expectedInvoices, $tx['invoices'], 'Blesta must apply the amounts the client chose; the rest stays as credit.');
        }

        // The Blesta invoice changed after checkout -> still held for review (unchanged rule).
        $gw = makeGateway($db, $transport, $clock);
        assertTrue($gw->buildProcess(array('client_id' => '9'), '40.00', array(array('id' => 803, 'amount' => '40.00'))) !== '');
        $due = '150.00';
        $snapshot = $snapshots->findByInvoiceId('inv_part_3');
        $body = invoiceWebhook('evt_part_changed', 'invoice.paid', sandboxInvoice('inv_part_3', $snapshot['external_order_id'], 'paid', '40'), $now);
        $sig = signWebhook($body, 'whsec_sandbox_blesta_secret_001', $now);
        $gw->setWebhookInputProvider(static fn (): string => $body, static fn (): array => array('X-Webhook-Signature' => $sig));
        assertSame(null, $gw->validate(array(), array()));
        assertSame(400, $gw->getLastHttpStatus(), 'A Blesta invoice edited after checkout must stay in manual review.');
    } finally {
        Loader::$models = $prev;
    }
});

runTest('8.4 CMSP-08: a paid Paymos invoice is never reused; the next top-up or payment of a reopened invoice gets a new attempt', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $snapshots = new SnapshotStore($db);
    $posts = array();
    $transport = new SpyTransport(function (array $req) use (&$posts): HttpResponse {
        if ($req['method'] !== 'POST') {
            throw new \RuntimeException('Only create is expected, got ' . $req['method'] . ' ' . $req['url']);
        }
        $posts[] = json_decode((string) $req['body'], true);
        $id = 'inv_top_' . count($posts);
        return jsonResponse(201, array('invoice_id' => $id, 'payment_url' => 'https://checkout.paymos.io/inv/' . $id, 'status' => 'awaiting_client'));
    });
    $checkout = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));

    $first = $checkout->createCheckout('9', '50.00', 'USD', array());
    $snapshots->updateStatus($first['paymos_invoice_id'], 'paid', '0xtop1');
    $second = $checkout->createCheckout('9', '50.00', 'USD', array());
    assertSame(false, $second['reused'], 'A second top-up must not reuse the paid checkout.');
    assertSame('inv_top_2', $second['paymos_invoice_id']);
    assertTrue($second['external_order_id'] !== $first['external_order_id'], 'A new external_order_id is required.');
    assertSame(2, count($posts));

    $paidInvoice = $checkout->createCheckout('9', '40.00', 'USD', array(array('id' => 805, 'amount' => '40.00')));
    $snapshots->updateStatus($paidInvoice['paymos_invoice_id'], 'paid', '0xpart805');
    $rest = $checkout->createCheckout('9', '60.00', 'USD', array(array('id' => 805, 'amount' => '60.00')));
    assertSame('blesta_inv_805_r1', $rest['external_order_id'], 'The rest of a partly paid invoice must be payable.');
    assertSame(4, count($posts), 'No cancel/GET of the paid invoice is needed.');
});

runTest('8.5 CMSP-09: a final 4xx closes the creating intent; the next attempt sends the current amount with a new key', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $snapshots = new SnapshotStore($db);
    $posts = array();
    $plan = array('reject', 'ok');
    $transport = new SpyTransport(function (array $req) use (&$posts, &$plan): HttpResponse {
        $posts[] = json_decode((string) $req['body'], true);
        $step = array_shift($plan);
        if ($step === 'reject') {
            return new HttpResponse(400, (string) json_encode(array('type' => 'about:blank', 'title' => 'Bad Request', 'status' => 400, 'detail' => 'Invoice amount is below the minimum.', 'code' => 'invoice_amount_below_minimum', 'field' => 'amount')), array('Content-Type' => 'application/problem+json'));
        }
        if ($step === 'crash') {
            throw new \RuntimeException('Paymos HTTP error: connection reset');
        }
        $id = 'inv_4xx_' . count($posts);
        return jsonResponse(201, array('invoice_id' => $id, 'payment_url' => 'https://checkout.paymos.io/inv/' . $id, 'status' => 'awaiting_client'));
    });
    $checkout = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));

    assertThrows(\Paymos\Exception\ApiException::class, static fn () => $checkout->createCheckout('9', '0.50', 'USD', array(array('id' => 810, 'amount' => '0.50'))));
    $failed = $snapshots->findByExternalOrderId('blesta_inv_810_r0');
    assertSame(CheckoutService::STATUS_CREATE_FAILED, $failed['status'], 'A definitive 4xx must end the creating intent.');

    $retry = $checkout->createCheckout('9', '15.00', 'USD', array(array('id' => 810, 'amount' => '15.00')));
    assertSame('blesta_inv_810_r1', $retry['external_order_id']);
    assertSame('15', $posts[1]['amount'], 'The next attempt must send the current amount.');
    assertSame(2, count($posts));

    // Lost response, then the replay is refused for good -> closed and a new key is cut in the same call.
    $plan = array('crash', 'reject', 'ok');
    $posts = array();
    assertThrows(\RuntimeException::class, static fn () => $checkout->createCheckout('9', '30.00', 'USD', array(array('id' => 811, 'amount' => '30.00'))));
    assertSame(CheckoutService::STATUS_CREATING, $snapshots->findByExternalOrderId('blesta_inv_811_r0')['status']);
    $next = $checkout->createCheckout('9', '35.00', 'USD', array(array('id' => 811, 'amount' => '35.00')));
    assertSame('blesta_inv_811_r1', $next['external_order_id']);
    assertSame(array('30', '30', '35'), array_column($posts, 'amount'));
    assertSame(CheckoutService::STATUS_CREATE_FAILED, $snapshots->findByExternalOrderId('blesta_inv_811_r0')['status']);
    foreach ($snapshots->findUnpaidRecent(50, 0) as $row) {
        assertTrue($row['status'] !== CheckoutService::STATUS_CREATE_FAILED, 'The reconciler never picks create_failed rows.');
    }
});

runTest('8.6 CMSP-10: a passed local expires_at is re-read from the server before refusing the buyer', function () {
    $db = new InMemoryDbDriver();
    $now = 1764110000;
    $clock = static fn (): int => $now;
    $store = new CredentialStore($db, null, $clock);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $snapshots = new SnapshotStore($db, $clock);
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_815_r0',
        'paymos_invoice_id' => 'inv_ttl_815',
        'platform_ref' => 'inv_815',
        'blesta_invoice_id' => 815,
        'amount' => '20',
        'payment_url' => 'https://checkout.paymos.io/inv/inv_ttl_815',
        'status' => 'awaiting_client',
        'expires_at' => $now - 600,
    ));
    $transport = new SpyTransport(function (array $req) use ($now): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($req['method'] === 'GET' && $path === '/v1/invoices/inv_ttl_815') {
            return jsonResponse(200, sandboxInvoice('inv_ttl_815', 'blesta_inv_815_r0', 'awaiting_payment', '20', array('expires_at' => $now + 1200)));
        }
        if ($req['method'] === 'POST' && $path === '/v1/invoices/inv_ttl_815/cancel') {
            return jsonResponse(409, array('type' => 'about:blank', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Cannot cancel.', 'code' => 'invoice_cannot_be_cancelled'));
        }
        throw new \RuntimeException('Unexpected request: ' . $req['method'] . ' ' . $path);
    });
    $checkout = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport), null, $clock);
    $res = $checkout->createCheckout('9', '20.00', 'USD', array(array('id' => 815, 'amount' => '20.00')));
    assertSame(true, $res['reused'], 'The invoice the server still accepts payment for must be reused.');
    assertSame('https://checkout.paymos.io/inv/inv_ttl_815', $res['payment_url']);
    assertSame(array('GET'), array_column($transport->requests, 'method'), 'Only a read is needed; no cancel.');
});

runTest('8.7 CMSP-11: the "already paid in Blesta" guard keeps the recorded status; a real Paymos payment after it is flagged for review', function () {
    $db = new InMemoryDbDriver();
    $now = 1764120000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $credStore);
    $snapshots = new SnapshotStore($db, $clock);
    $eventStore = new PdoEventStore($db, $clock);
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_820_r0',
        'paymos_invoice_id' => 'inv_card_820',
        'platform_ref' => 'inv_820',
        'blesta_invoice_id' => 820,
        'amount' => '30',
        'status' => 'awaiting_payment',
    ));
    $notes = array();
    $apiStatus = 'confirming';
    $transport = new SpyTransport(function (array $req) use (&$apiStatus): HttpResponse {
        return jsonResponse(200, sandboxInvoice('inv_card_820', 'blesta_inv_820_r0', $apiStatus, '30', array('payment' => array('tx_hash' => '0xcrypto820'))));
    });
    $processor = new CallbackProcessor(
        $config,
        $snapshots,
        $eventStore,
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport),
        static fn (): array => array('paid' => true, 'amount' => '0', 'currency' => 'USD'),
        null,
        null,
        $clock,
        null,
        function (array $snapshot, string $title, string $body) use (&$notes): void {
            $notes[] = array('client_id' => $snapshot['client_id'], 'title' => $title, 'body' => $body);
        }
    );

    $confirming = invoiceWebhook('evt_card_confirming', 'invoice.confirming', sandboxInvoice('inv_card_820', 'blesta_inv_820_r0', 'confirming', '30'), $now);
    $res1 = $processor->handle($confirming, signWebhook($confirming, 'whsec_sandbox_blesta_secret_001', $now));
    assertSame(200, $res1['http_status']);
    assertSame('ignored_already_paid', $res1['status']);
    assertTrue($snapshots->findByInvoiceId('inv_card_820')['status'] !== 'paid', 'A non-payment event must not write paid.');
    assertSame(null, $snapshots->findPaidByBlestaInvoiceId(820), 'No Paymos payment exists yet.');

    $apiStatus = 'paid';
    $paid = invoiceWebhook('evt_card_paid', 'invoice.paid', sandboxInvoice('inv_card_820', 'blesta_inv_820_r0', 'paid', '30'), $now);
    $res2 = $processor->handle($paid, signWebhook($paid, 'whsec_sandbox_blesta_secret_001', $now));
    assertSame(200, $res2['http_status']);
    assertSame(null, $res2['transaction'], 'Blesta invoice is closed: nothing is recorded automatically.');
    $meta = SnapshotStore::decodeMeta($snapshots->findByInvoiceId('inv_card_820')['payload_meta']);
    assertSame(true, $meta['manual_review'] ?? null, 'The Paymos payment must be flagged for review.');
    assertSame('blesta_invoice_already_paid', $meta['manual_review_reason'] ?? null);
    assertSame(1, count($notes), 'Admin must see a native note.');
    assertSame('9', $notes[0]['client_id']);
});

runTest('8.8 CMSP-12: paid_late and manual review leave one native Blesta client note', function () {
    $db = new InMemoryDbDriver();
    $now = 1764130000;
    $clock = static fn (): int => $now;
    (new CredentialStore($db, null, $clock))->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_825_r0',
        'paymos_invoice_id' => 'inv_late_825',
        'platform_ref' => 'inv_825',
        'blesta_invoice_id' => 825,
        'client_id' => '31',
        'amount' => '45',
        'status' => 'expired',
        'final_status' => 'expired',
    ));
    $prev = Loader::$models;
    $added = array();
    Loader::$models['Clients'] = new class($added) {
        public $added;
        public function __construct(&$added) { $this->added = &$added; }
        public function addNote($clientId, $staffId, array $vars) { $this->added[] = array('client_id' => $clientId, 'staff_id' => $staffId, 'vars' => $vars); return count($this->added); }
        public function errors() { return false; }
    };
    $late = sandboxInvoice('inv_late_825', 'blesta_inv_825_r0', 'expired', '45', array('paid_late_at' => $now - 30));
    $transport = new SpyTransport(static fn (): HttpResponse => jsonResponse(200, $late));
    try {
        foreach (array('evt_late_825_a', 'evt_late_825_b') as $eventId) {
            $body = invoiceWebhook($eventId, 'invoice.paid_late', $late, $now);
            $sig = signWebhook($body, 'whsec_sandbox_blesta_secret_001', $now);
            $gw = makeGateway($db, $transport, $clock);
            $gw->setWebhookInputProvider(static fn (): string => $body, static fn (): array => array('X-Webhook-Signature' => $sig));
            $gw->validate(array(), array());
            assertSame(200, $gw->getLastHttpStatus());
        }
        assertSame(1, count($added), 'paid_late must add exactly one client note.');
        assertSame(31, $added[0]['client_id']);
        assertSame(null, $added[0]['staff_id']);
        assertSame(1, $added[0]['vars']['stickied']);
        assertTrue(strpos($added[0]['vars']['description'], 'inv_late_825') !== false, 'The note names the Paymos invoice.');

        // Amount mismatch: retried delivery keeps one note.
        seedSnapshot($snapshots, array(
            'external_order_id' => 'blesta_inv_826_r0',
            'paymos_invoice_id' => 'inv_mm_826',
            'platform_ref' => 'inv_826',
            'blesta_invoice_id' => 826,
            'client_id' => '32',
            'amount' => '45',
            'status' => 'awaiting_payment',
        ));
        $paid = sandboxInvoice('inv_mm_826', 'blesta_inv_826_r0', 'paid', '45');
        $mmTransport = new SpyTransport(static fn (): HttpResponse => jsonResponse(200, $paid));
        $body = invoiceWebhook('evt_mm_826', 'invoice.paid', $paid, $now);
        $sig = signWebhook($body, 'whsec_sandbox_blesta_secret_001', $now);
        for ($i = 0; $i < 2; $i++) {
            $gw = makeGateway($db, $mmTransport, $clock);
            $gw->setBillingStateResolver(static fn (): array => array('paid' => false, 'amount' => '60', 'currency' => 'USD'));
            $gw->setWebhookInputProvider(static fn (): string => $body, static fn (): array => array('X-Webhook-Signature' => $sig));
            $gw->validate(array(), array());
            assertSame(400, $gw->getLastHttpStatus());
        }
        assertSame(2, count($added), 'Manual review must add exactly one more note across retries.');
        assertSame(32, $added[1]['client_id']);
    } finally {
        Loader::$models = $prev;
    }
});

runTest('8.9 CMSP-13: a fresh create never binds a final invoice the server returned for a reused key', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $snapshots = new SnapshotStore($db);
    $posts = array();
    $transport = new SpyTransport(function (array $req) use (&$posts): HttpResponse {
        $body = json_decode((string) $req['body'], true);
        $posts[] = $body;
        if ($body['external_order_id'] === 'blesta_inv_830_r0') {
            return jsonResponse(200, array('invoice_id' => 'inv_foreign_old', 'payment_url' => 'https://checkout.paymos.io/inv/inv_foreign_old', 'status' => 'paid'));
        }
        return jsonResponse(201, array('invoice_id' => 'inv_new_830', 'payment_url' => 'https://checkout.paymos.io/inv/inv_new_830', 'status' => 'awaiting_client'));
    });
    $checkout = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));
    $res = $checkout->createCheckout('9', '100.00', 'USD', array(array('id' => 830, 'amount' => '100.00')));
    assertSame('inv_new_830', $res['paymos_invoice_id'], 'A paid invoice of another installation must not be bound.');
    assertSame('blesta_inv_830_r1', $res['external_order_id']);
    assertSame(null, $snapshots->findByInvoiceId('inv_foreign_old'), 'The foreign invoice is never stored.');
    assertSame(CheckoutService::STATUS_CREATE_FAILED, $snapshots->findByExternalOrderId('blesta_inv_830_r0')['status']);
    assertSame(2, count($posts));
});

runTest('8.10 CMSP-14: after a failed dedup commit the retried webhook still hands Blesta the payment', function () {
    $inner = new InMemoryDbDriver();
    $db = new CommitFailingDb($inner);
    $now = 1764140000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $credStore = new CredentialStore($inner, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $credStore);
    $snapshots = new SnapshotStore($inner, $clock);
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_835_r0',
        'paymos_invoice_id' => 'inv_crash_835',
        'platform_ref' => 'inv_835',
        'blesta_invoice_id' => 835,
        'payload_meta' => json_encode(array('invoices' => array(array('id' => 835, 'amount' => '25')))),
        'amount' => '25',
        'status' => 'awaiting_payment',
    ));
    $paid = sandboxInvoice('inv_crash_835', 'blesta_inv_835_r0', 'paid', '25', array('payment' => array('tx_hash' => '0xcrash835')));
    $transport = new SpyTransport(static fn (): HttpResponse => jsonResponse(200, $paid));
    $processor = new CallbackProcessor(
        $config,
        $snapshots,
        new PdoEventStore($db, $clock),
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport),
        static fn (): array => array('paid' => false, 'amount' => '25', 'currency' => 'USD'),
        null,
        null,
        $clock,
        null,
        null,
        static fn (string $txId): ?bool => false
    );
    $body = invoiceWebhook('evt_crash_835', 'invoice.paid', $paid, $now);
    $db->failNextEventCommit = true;
    $first = $processor->handle($body, signWebhook($body, 'whsec_sandbox_blesta_secret_001', $now));
    assertTrue($first['http_status'] >= 400, 'A failed commit must not acknowledge the delivery.');
    assertSame(null, $first['transaction'], 'Blesta records nothing on a failed delivery.');

    $now += PdoEventStore::RESERVATION_SECONDS + 1;
    $retry = $processor->handle($body, signWebhook($body, 'whsec_sandbox_blesta_secret_001', $now));
    assertSame(200, $retry['http_status']);
    assertTrue(is_array($retry['transaction']), 'The retry must hand Blesta the payment again (Blesta upserts by transaction_id).');
    assertSame('0xcrash835', $retry['transaction']['transaction_id']);
    assertSame('25', $retry['transaction']['amount']);
    assertSame(array(array('id' => 835, 'amount' => '25')), $retry['transaction']['invoices']);
});

runTest('8.11 CMSP-15: cron records a payment once: rejected Transactions::add stays retryable, a stale row after the webhook adds nothing', function () {
    $db = new InMemoryDbDriver();
    $now = 1764150000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_840_r0',
        'paymos_invoice_id' => 'inv_cron_840',
        'platform_ref' => 'inv_840',
        'blesta_invoice_id' => 840,
        'payload_meta' => json_encode(array('invoices' => array(array('id' => 840, 'amount' => '15')))),
        'amount' => '15',
        'status' => 'awaiting_payment',
        'created_at' => $now - 300,
    ));
    $prev = Loader::$models;
    $added = array();
    $addResult = null;
    $existing = null;
    Loader::$models['Invoices'] = new class {
        public function get(int $id) { return (object) array('id' => $id, 'client_id' => 9, 'status' => 'active', 'date_closed' => null, 'total' => '15', 'due' => '15', 'currency' => 'USD'); }
    };
    Loader::$models['GatewayManager'] = new class {
        public function getByClass(string $class, int $companyId) { return array((object) array('id' => 42)); }
    };
    Loader::$models['Transactions'] = new class($added, $addResult, $existing) {
        public $added; public $addResult; public $existing;
        public function __construct(&$added, &$addResult, &$existing) { $this->added = &$added; $this->addResult = &$addResult; $this->existing = &$existing; }
        public function getByTransactionId($txId, $clientId = null, $gatewayId = null) { return $this->existing; }
        public function add(array $vars) { $this->added[] = $vars; return $this->addResult; }
        public function apply($txId, array $vars): void {}
        public function errors() { return $this->addResult ? false : array('amount' => array('format' => 'Amount is invalid.')); }
    };
    $transport = new SpyTransport(static function (array $req): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($path === '/v1/invoices/inv_cron_840') {
            return jsonResponse(200, sandboxInvoice('inv_cron_840', 'blesta_inv_840_r0', 'paid', '15', array('payment' => array('tx_hash' => '0xcron840'))));
        }
        return jsonResponse(200, array('items' => array(), 'next_cursor' => null));
    });
    try {
        $plugin = new PaymosPlugin();
        $plugin->setDbDriver($db);
        $plugin->setClock($clock);
        $plugin->setMeta(array('mode' => 'sandbox'));
        $plugin->setClientFactory(static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));
        $res = $plugin->cron(PaymosPlugin::CRON_KEY);
        assertSame(1, count($added), 'Transactions::add was attempted.');
        assertSame(1, $res['reconcile']['errors'], 'A rejected Transactions::add must be an error.');
        assertSame('awaiting_payment', $snapshots->findByInvoiceId('inv_cron_840')['status'], 'The snapshot must stay retryable.');

        // Blesta already has the transaction (recorded by the webhook) -> no second add.
        $existing = (object) array('id' => 9840, 'transaction_id' => '0xcron840');
        $now += Reconciler::THROTTLE_SECONDS + 1;
        $plugin->cron(PaymosPlugin::CRON_KEY);
        assertSame(1, count($added), 'An existing Blesta transaction must not be added again.');
        assertSame('paid', $snapshots->findByInvoiceId('inv_cron_840')['status']);

        // A late webhook for a payment the cron confirmed in Blesta is not handed over again
        // (that would only re-send Blesta's payment email).
        $lateHook = new CallbackProcessor(
            Config::fromStore(array('mode' => 'sandbox'), $credStore),
            $snapshots,
            new PdoEventStore($db, $clock),
            static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport),
            static fn (): array => array('paid' => true, 'amount' => '0', 'currency' => 'USD'),
            null,
            null,
            $clock
        );
        $lateBody = invoiceWebhook('evt_cron_840_late', 'invoice.paid', sandboxInvoice('inv_cron_840', 'blesta_inv_840_r0', 'paid', '15'), $now);
        $late = $lateHook->handle($lateBody, signWebhook($lateBody, 'whsec_sandbox_blesta_secret_001', $now));
        assertSame(200, $late['http_status']);
        assertSame(null, $late['transaction'], 'Blesta already holds the cron-recorded transaction.');
    } finally {
        Loader::$models = $prev;
    }

    // Stale row read before the webhook marked it paid must not apply a second time.
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_841_r0',
        'paymos_invoice_id' => 'inv_race_841',
        'platform_ref' => 'inv_841',
        'blesta_invoice_id' => 841,
        'amount' => '15',
        'status' => 'awaiting_payment',
    ));
    $staleRow = $snapshots->findByExternalOrderId('blesta_inv_841_r0');
    $paid = sandboxInvoice('inv_race_841', 'blesta_inv_841_r0', 'paid', '15', array('payment' => array('tx_hash' => '0xrace841')));
    $raceTransport = new SpyTransport(static fn (): HttpResponse => jsonResponse(200, $paid));
    $applied = 0;
    $processor = new CallbackProcessor(
        Config::fromStore(array('mode' => 'sandbox'), $credStore),
        $snapshots,
        new PdoEventStore($db, $clock),
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $raceTransport),
        static fn (): array => array('paid' => false, 'amount' => '15', 'currency' => 'USD'),
        null,
        function () use (&$applied): void {
            $applied++;
        },
        $clock
    );
    $body = invoiceWebhook('evt_race_841', 'invoice.paid', $paid, $now);
    $hook = $processor->handle($body, signWebhook($body, 'whsec_sandbox_blesta_secret_001', $now));
    assertTrue(is_array($hook['transaction']), 'The webhook hands the payment to Blesta.');
    assertSame(false, $processor->applyTrustedInvoice($paid, $staleRow, $now), 'The cron must re-read the row and skip it.');
    assertSame(0, $applied, 'The cron applier must not record the payment a second time.');
});

runTest('8.12 CMSP-17: cron resolves the Blesta state like the webhook (due across every invoice of the payment)', function () {
    $db = new InMemoryDbDriver();
    $now = 1764160000;
    $clock = static fn (): int => $now;
    (new CredentialStore($db, null, $clock))->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_845_846_r0',
        'paymos_invoice_id' => 'inv_multi_845',
        'platform_ref' => 'inv_845_846',
        'blesta_invoice_id' => null,
        'payload_meta' => json_encode(array('invoices' => array(array('id' => 845, 'amount' => '25'), array('id' => 846, 'amount' => '25')), 'invoice_total' => '50')),
        'amount' => '50',
        'status' => 'awaiting_payment',
        'created_at' => $now - 300,
    ));
    $prev = Loader::$models;
    $added = array();
    $applied = array();
    Loader::$models['Invoices'] = new class {
        public function get(int $id) { return (object) array('id' => $id, 'client_id' => 9, 'status' => 'active', 'date_closed' => null, 'total' => '40.00', 'due' => '25.00', 'currency' => 'USD'); }
    };
    Loader::$models['GatewayManager'] = new class {
        public function getByClass(string $class, int $companyId) { return array((object) array('id' => 42)); }
    };
    Loader::$models['Transactions'] = new class($added, $applied) {
        public $added; public $applied;
        public function __construct(&$added, &$applied) { $this->added = &$added; $this->applied = &$applied; }
        public function add(array $vars) { $this->added[] = $vars; return 9845; }
        public function apply($txId, array $vars): void { $this->applied[] = $vars; }
        public function errors() { return false; }
    };
    $transport = new SpyTransport(static function (array $req): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($path === '/v1/invoices/inv_multi_845') {
            return jsonResponse(200, sandboxInvoice('inv_multi_845', 'blesta_inv_845_846_r0', 'paid', '50', array('payment' => array('tx_hash' => '0xmulti845'))));
        }
        return jsonResponse(200, array('items' => array(), 'next_cursor' => null));
    });
    try {
        $plugin = new PaymosPlugin();
        $plugin->setDbDriver($db);
        $plugin->setClock($clock);
        $plugin->setMeta(array('mode' => 'sandbox'));
        $plugin->setClientFactory(static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));
        $res = $plugin->cron(PaymosPlugin::CRON_KEY);
        assertSame(1, $res['reconcile']['applied'], 'A two-invoice payment must be applied by the cron: ' . json_encode($plugin->getLogs()));
        assertSame('50', $added[0]['amount']);
        assertSame(array(array('invoice_id' => 845, 'amount' => '25'), array('invoice_id' => 846, 'amount' => '25')), $applied[0]['amounts']);
    } finally {
        Loader::$models = $prev;
    }
});

runTest('8.13 CMSP-33: a non-final status never overwrites a recorded final status', function () {
    $db = new InMemoryDbDriver();
    $snapshots = new SnapshotStore($db);
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_850_r0',
        'paymos_invoice_id' => 'inv_order_850',
        'platform_ref' => 'inv_850',
        'blesta_invoice_id' => 850,
        'status' => 'awaiting_payment',
    ));
    $snapshots->updateStatus('inv_order_850', 'paid', '0xorder850');
    $snapshots->updateStatus('inv_order_850', 'confirming');
    $row = $snapshots->findByInvoiceId('inv_order_850');
    assertSame('paid', $row['status']);
    assertSame('paid', $row['final_status']);
    assertTrue(is_array($snapshots->findPaidByBlestaInvoiceId(850)), 'The paid payment must stay findable for refunds.');
});

runTest('8.14 CMSP-37: a zero-price line item keeps products[] (only negative prices fall back to amount-only)', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $sent = array();
    $transport = new SpyTransport(function (array $req) use (&$sent): HttpResponse {
        $sent[] = json_decode((string) $req['body'], true);
        $id = 'inv_free_' . count($sent);
        return jsonResponse(201, array('invoice_id' => $id, 'payment_url' => 'https://checkout.paymos.io/inv/' . $id, 'status' => 'awaiting_client'));
    });
    $checkout = new CheckoutService($config, new SnapshotStore($db), static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));
    $checkout->createCheckout('9', '12.00', 'USD', array(array('id' => 855, 'amount' => '12.00')), array('line_items' => array(
        array('description' => 'example.com registration', 'qty' => 1, 'amount' => '12.00'),
        array('description' => 'Setup fee', 'qty' => 1, 'amount' => '0.00'),
    )));
    assertSame(2, count($sent[0]['products'] ?? array()), 'A free line item must not drop the whole product list.');
    assertSame('0', $sent[0]['products'][1]['unit_amount']);

    $checkout->createCheckout('9', '10.00', 'USD', array(array('id' => 856, 'amount' => '10.00')), array('line_items' => array(
        array('description' => 'Hosting', 'qty' => 1, 'amount' => '12.00'),
        array('description' => 'Discount', 'qty' => 1, 'amount' => '-2.00'),
    )));
    assertTrue(!isset($sent[1]['products']), 'A negative line item still falls back to amount-only.');
});

runTest('8.15 CMSP-16: a payment of two invoices is refundable from either invoice; a credit-only payment is refused with a clear message', function () {
    $db = new InMemoryDbDriver();
    $now = 1764170000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_860_861_r0',
        'paymos_invoice_id' => 'inv_rf_860',
        'platform_ref' => 'inv_860_861',
        'blesta_invoice_id' => null,
        'client_id' => '90',
        'payload_meta' => json_encode(array('invoices' => array(array('id' => 860, 'amount' => '25'), array('id' => 861, 'amount' => '25')))),
        'amount' => '50',
        'transaction_id' => '0xtx860',
        'status' => 'paid',
        'final_status' => 'paid',
    ));
    $edits = 0;
    $tx = array('id' => '9860', 'client_id' => '90', 'transaction_id' => '0xtx860', 'reference_id' => 'inv_rf_860', 'amount' => '50', 'currency' => 'USD', 'status' => 'approved', 'notes' => '');
    $native = function (string $cmd, array $input) use (&$tx, &$edits, $snapshots): array {
        if ($cmd === 'snapshot') {
            return array(
                'invoice' => array('id' => (int) $input['invoice_id'], 'client_id' => '90', 'status' => 'active', 'total' => '25', 'currency' => 'USD'),
                'transaction' => $tx,
                'mapping' => $snapshots->findPaidByBlestaInvoiceId((int) $input['invoice_id']),
            );
        }
        if ($cmd === 'marker') {
            return ($tx['status'] === 'refunded' && strpos($tx['notes'], (string) $input['marker']) !== false) ? array($tx) : array();
        }
        if ($cmd === 'Transactions::edit') {
            $edits++;
            $tx['status'] = $input['vars']['status'];
            $tx['notes'] = $input['vars']['notes'];
            return array('result' => 'success');
        }
        return array();
    };
    $ext = '';
    $transport = new SpyTransport(function (array $req) use (&$ext): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($req['method'] === 'POST' && $path === '/v1/refunds') {
            $ext = json_decode((string) $req['body'], true)['external_order_id'];
            return jsonResponse(201, array('withdrawal_id' => 'wd_rf_860', 'status' => 'completed'));
        }
        if ($path === '/v1/refunds/wd_rf_860') {
            return jsonResponse(200, array('withdrawal_id' => 'wd_rf_860', 'invoice_id' => 'inv_rf_860', 'project_id' => 'prj_sandbox_blesta_001', 'is_test' => true, 'mode' => 'full', 'external_order_id' => $ext, 'status' => 'completed', 'amount' => '50', 'currency' => 'USDT', 'network' => 'arbitrum', 'first_sender' => '0xsender860'));
        }
        if ($path === '/v1/invoices/inv_rf_860') {
            return jsonResponse(200, sandboxInvoice('inv_rf_860', 'blesta_inv_860_861_r0', 'paid', '50', array('refund_summary' => array('can_refund_full' => true, 'can_refund_overpayment' => false, 'full_refund_amount' => '50', 'overpayment_refund_amount' => null))));
        }
        if ($path === '/v1/refunds') {
            return jsonResponse(200, array('items' => array(), 'next_cursor' => null));
        }
        throw new \RuntimeException('Unexpected request: ' . $req['method'] . ' ' . $path);
    });

    $controller = new AdminRefunds();
    $controller->setDbDriver($db);
    $controller->setClock($clock);
    $controller->setMeta(array('mode' => 'sandbox'));
    $controller->setClientFactory(static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));
    $controller->setRefundNativeHandler($native);
    $controller->setStaffAuthenticator(static fn (): bool => true);
    $controller->setCsrfVerifier(static fn (string $t): bool => $t === 'csrf_ok');

    $panel = $controller->handlePanelRequest(861, 'en_us');
    assertSame(200, $panel['http_status']);
    assertSame(true, $panel['body']['can_refund_full'] ?? null, 'Invoice #861 is covered by the two-invoice payment.');
    $ok = $controller->handleSubmitRequest('POST', array(
        '_csrf_token' => 'csrf_ok',
        'invoice_id' => '861',
        'mode' => 'full',
        'request_token' => CredentialStore::uuidV4(),
    ));
    assertSame(200, $ok['http_status'], 'Refund submit failed: ' . json_encode($ok['body']));
    assertSame(RefundStates::PROJECTION_APPLIED, $ok['body']['projection_state']);
    assertSame(1, $edits, 'The one Blesta transaction behind both invoices is marked refunded once.');

    // Credit-only (no invoice) payment: native refund explains why it is not available here.
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_amt_credit_r0',
        'paymos_invoice_id' => 'inv_credit_862',
        'platform_ref' => 'amt_0123456789abcdef01234567',
        'blesta_invoice_id' => null,
        'payload_meta' => json_encode(array('invoices' => array())),
        'amount' => '20',
        'transaction_id' => '0xcredit862',
        'status' => 'paid',
        'final_status' => 'paid',
    ));
    $adapter = new RefundAdapter(Config::fromStore(array('mode' => 'sandbox'), $credStore), $credStore, $snapshots, new RefundIntentStore($db, $credStore, $clock), static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport), $native, null, $clock);
    assertSame(null, $adapter->nativeRefund(array('reference_id' => 'inv_credit_862', 'transaction_id' => '0xcredit862', 'amount' => '20', 'locale' => 'en_us')));
    assertSame(Translation::text('Paymos.!error.refund.credit_unsupported', 'en_us'), $adapter->lastNativeMessage());
});

runTest('8.16 CMSP-18: admin URLs keep the Blesta web directory (subfolder, no mod_rewrite)', function () {
    assertSame('/billing/admin/plugin/paymos/admin_connect/', Config::adminUri('plugin/paymos/admin_connect/', '/billing/'));
    assertSame('/billing/index.php/admin/plugin/paymos/admin_connect/', Config::adminUri('plugin/paymos/admin_connect/', '/billing/index.php/'));
    assertSame('/admin/plugin/paymos/admin_refunds/', Config::adminUri('plugin/paymos/admin_refunds/', null));
    Configure::set('Route.admin', 'staff');
    try {
        assertSame('/billing/staff/plugin/paymos/admin_connect/', Config::adminUri('plugin/paymos/admin_connect/', '/billing/'));
    } finally {
        Configure::set('Route.admin', 'admin');
    }

    $db = new InMemoryDbDriver();
    $controller = new AdminRefunds();
    $controller->setDbDriver($db);
    $controller->setStaffAuthenticator(static fn (): bool => true);
    $controller->base_uri = '/billing/index.php/admin/';
    $page = $controller->handleIndexPage(0, 'en_us');
    assertTrue(strpos($page['html'], 'data-submit-url="/billing/index.php/admin/plugin/paymos/admin_refunds/submit/"') !== false, 'Submit URL must use the controller base URI.');
    assertTrue(strpos($page['html'], 'action="/billing/index.php/admin/plugin/paymos/admin_refunds/"') !== false, 'Lookup form must use the controller base URI.');
});

runTest('8.17 CMSP-34: refunds page and admin errors are localized; companion plugin ships 6 locales', function () use ($companionRoot) {
    $db = new InMemoryDbDriver();
    $controller = new AdminRefunds();
    $controller->setDbDriver($db);
    $controller->setStaffAuthenticator(static fn (): bool => true);
    $page = $controller->handleIndexPage(0, 'ru_ru');
    foreach (array('Blesta Invoice ID', '>Load<', 'Refund failed', 'Network error', '"Refund "') as $hardcoded) {
        assertTrue(strpos($page['html'], $hardcoded) === false, 'Hardcoded English text on the ru_ru page: ' . $hardcoded);
    }
    assertTrue(strpos($page['html'], htmlspecialchars(Translation::text('Paymos.refunds.lookup_label', 'ru_ru'), ENT_QUOTES, 'UTF-8')) !== false);

    $controller->setStaffAuthenticator(static fn (): bool => false);
    $denied = $controller->handleSubmitRequest('POST', array('_csrf_token' => 'x'));
    assertSame(403, $denied['http_status']);
    assertSame('paymos_admin_access_denied', $denied['body']['reason'] ?? null);
    assertSame(Language::_('PaymosPlugin.error.access_denied', true), $denied['body']['error']);
    $connect = new AdminConnect();
    $connect->setDbDriver($db);
    $connect->setStaffAuthenticator(static fn (): bool => true);
    $connect->setCsrfVerifier(static fn (string $t): bool => false);
    $badCsrf = $connect->handleRequest('POST', array('action' => 'start', '_csrf_token' => 'nope'));
    assertSame(Language::_('PaymosPlugin.error.csrf_invalid', true), $badCsrf['body']['error']);
    assertSame('paymos_csrf_invalid', $badCsrf['body']['reason'] ?? null);
    $badMethod = $connect->handleRequest('GET', array());
    assertSame(Language::_('PaymosPlugin.error.method_not_allowed', true), $badMethod['body']['error']);

    $load = static function (string $locale) use ($companionRoot): array {
        $path = $companionRoot . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . 'paymos_plugin.php';
        assertTrue(is_file($path), 'Missing companion locale ' . $locale);
        $lang = array();
        include $path;
        return $lang;
    };
    $en = $load('en_us');
    $enKeys = array_keys($en);
    sort($enKeys);
    foreach (array('PaymosPlugin.permission.admin_main', 'PaymosPlugin.permission.admin_connect', 'PaymosPlugin.permission.admin_refunds', 'PaymosPlugin.error.invalid_action') as $required) {
        assertTrue(isset($en[$required]), 'en_us must define ' . $required);
    }
    foreach (array('ru_ru', 'de_de', 'es_es', 'tr_tr', 'zh_cn') as $locale) {
        $cat = $load($locale);
        $keys = array_keys($cat);
        sort($keys);
        assertSame($enKeys, $keys, 'Companion key mismatch in ' . $locale);
        foreach ($cat as $key => $value) {
            assertTrue(trim((string) $value) !== '', "Empty {$key} in {$locale}");
            if ($key !== 'PaymosPlugin.permission.admin_main') {
                assertTrue($value !== $en[$key], "Untranslated {$key} in {$locale}");
            }
        }
    }
});

runTest('8.18 CMSP-35: the native refund button can retry after a failed refund', function () {
    $db = new InMemoryDbDriver();
    $now = 1764180000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    seedSnapshot($snapshots, array(
        'external_order_id' => 'blesta_inv_870_r0',
        'paymos_invoice_id' => 'inv_rf_870',
        'platform_ref' => 'inv_870',
        'blesta_invoice_id' => 870,
        'client_id' => '33',
        'amount' => '49.90',
        'transaction_id' => '0xtx870',
        'status' => 'paid',
        'final_status' => 'paid',
    ));
    $native = function (string $cmd, array $input) use ($snapshots): array {
        if ($cmd === 'snapshot') {
            return array(
                'invoice' => array('id' => 870, 'client_id' => '33', 'status' => 'active', 'total' => '49.90', 'currency' => 'USD'),
                'transaction' => array('id' => '9870', 'client_id' => '33', 'transaction_id' => '0xtx870', 'reference_id' => 'inv_rf_870', 'amount' => '49.90', 'currency' => 'USD', 'status' => 'approved', 'notes' => ''),
                'mapping' => $snapshots->findPaidByBlestaInvoiceId(870),
            );
        }
        return array();
    };
    $posts = 0;
    $lastExt = '';
    $transport = new SpyTransport(function (array $req) use (&$posts, &$lastExt): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($req['method'] === 'POST' && $path === '/v1/refunds') {
            $posts++;
            if ($posts === 1) {
                return new HttpResponse(409, (string) json_encode(array('type' => 'about:blank', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Refund not allowed now.', 'code' => 'refund_not_allowed')), array('Content-Type' => 'application/problem+json'));
            }
            $lastExt = json_decode((string) $req['body'], true)['external_order_id'];
            return jsonResponse(201, array('withdrawal_id' => 'wd_rf_870', 'status' => 'signed'));
        }
        if ($req['method'] === 'GET' && $path === '/v1/refunds/wd_rf_870') {
            return jsonResponse(200, array('withdrawal_id' => 'wd_rf_870', 'invoice_id' => 'inv_rf_870', 'project_id' => 'prj_sandbox_blesta_001', 'is_test' => true, 'mode' => 'full', 'external_order_id' => $lastExt, 'status' => 'signed', 'amount' => '49.9', 'currency' => 'USDT', 'network' => 'arbitrum', 'first_sender' => '0xsender870'));
        }
        if ($req['method'] === 'GET' && $path === '/v1/invoices/inv_rf_870') {
            return jsonResponse(200, sandboxInvoice('inv_rf_870', 'blesta_inv_870_r0', 'paid', '49.90', array('refund_summary' => array('can_refund_full' => true, 'can_refund_overpayment' => false, 'full_refund_amount' => '49.9', 'overpayment_refund_amount' => null))));
        }
        throw new \RuntimeException('Unexpected request: ' . $req['method'] . ' ' . $path);
    });
    $gw = makeGateway($db, $transport, $clock);
    $gw->setRefundNativeHandler($native);
    assertSame(null, $gw->refund('inv_rf_870', '0xtx870', '49.90'));
    assertSame(1, $posts);
    $intents = (new RefundIntentStore($db, $credStore, $clock))->forPayment('870');
    assertSame(RefundStates::FAILED, $intents[0]->cashState(), 'The first refund failed.');

    assertSame(null, $gw->refund('inv_rf_870', '0xtx870', '49.90'));
    assertSame(2, $posts, 'Pressing Refund again must send a new refund request.');
    assertSame(null, $gw->refund('inv_rf_870', '0xtx870', '49.90'));
    assertSame(2, $posts, 'While the new refund is in progress the button reuses it.');
});

runTest('8.19 CMSP-36: the Appcontroller.structure hook registers nothing', function () {
    assertSame(array(), (new PaymosPlugin())->getEvents(), 'No events are registered.');
});

// ── Group 9: Independent review of the audit fixes (R-plugins-01..08) ──────────

/**
 * Blesta staff ACL (StaffGroups / Permissions / PluginManager) of one company.
 */
final class FakeBlestaAcl
{
    /** @var bool */
    public $permissionsRegistered = true;
    /** @var array<int, int> */
    public $groups = array(3, 4, 5);
    /** @var array<int, array<int, int>> */
    public $grants = array();
    /** @var array<string, bool> "staff_group_<id>|<aco>" */
    public $allowed = array();
    /** @var array<int, array<int, mixed>> */
    public $deletedEvents = array();
    /** @var array<int, int> staff id => group id */
    public $staffGroupOf = array(7 => 3, 8 => 4);

    public function install(): void
    {
        $acl = $this;
        Loader::$models['Permissions'] = new class($acl) {
            private $acl;
            public function __construct(FakeBlestaAcl $acl) { $this->acl = $acl; }
            public function getByAlias($alias, $pluginId = null, $action = null) { return $this->acl->permissionsRegistered ? (object) array('id' => 1, 'alias' => $alias, 'plugin_id' => $pluginId) : false; }
            public function authorized($aro, $aco, $action, $level, $companyId) { return !empty($this->acl->allowed[$aro . '|' . $aco]); }
        };
        Loader::$models['StaffGroups'] = new class($acl) {
            private $acl;
            public function __construct(FakeBlestaAcl $acl) { $this->acl = $acl; }
            public function getAll($companyId) { return array_map(static fn (int $id) => (object) array('id' => $id, 'company_id' => $companyId), $this->acl->groups); }
            public function getStaffGroupByStaff($staffId, $companyId) { return isset($this->acl->staffGroupOf[(int) $staffId]) ? (object) array('id' => $this->acl->staffGroupOf[(int) $staffId]) : false; }
            public function grantPermission($staffGroupId, $pluginId): void
            {
                $this->acl->grants[] = array((int) $staffGroupId, (int) $pluginId);
                if ($this->acl->permissionsRegistered) {
                    foreach (array('paymos.admin_connect', 'paymos.admin_refunds') as $aco) {
                        $this->acl->allowed['staff_group_' . (int) $staffGroupId . '|' . $aco] = true;
                    }
                }
            }
            public function errors() { return false; }
        };
        Loader::$models['PluginManager'] = new class($acl) {
            private $acl;
            public function __construct(FakeBlestaAcl $acl) { $this->acl = $acl; }
            public function getByDir($dir, $companyId = null) { return $dir === 'paymos' ? array((object) array('id' => 12, 'dir' => 'paymos')) : array(); }
            public function deleteEvent($pluginId, $event): void { $this->acl->deletedEvents[] = array((int) $pluginId, (string) $event); }
        };
    }
}

runTest('9.1 R-plugins-01: installs of 1.0.0 still find a no-op injectAdminHelpers until upgrade drops the event', function () {
    $prev = Loader::$models;
    try {
        $plugin = new PaymosPlugin();
        $plugin->setDbDriver(new InMemoryDbDriver());
        $event = new class {
            public $ret = array('head' => array('existing'));
            public function getParams(): array { return array('portal' => 'client', 'controller' => 'client_pay'); }
            public function getReturnValue(): array { return $this->ret; }
            public function setReturnValue(array $v): void { $this->ret = $v; }
        };
        // Blesta dispatches the stored 1.0.0 callback array('this', 'injectAdminHelpers').
        call_user_func(array($plugin, 'injectAdminHelpers'), $event);
        assertSame(array('head' => array('existing')), $event->ret, 'The leftover handler must change nothing.');

        $acl = new FakeBlestaAcl();
        $acl->install();
        $plugin->upgrade('1.0.0', 12);
        assertSame(array(array(12, 'Appcontroller.structure')), $acl->deletedEvents, 'Upgrade removes the old event registration.');
    } finally {
        Loader::$models = $prev;
    }
});

runTest('9.2 R-plugins-02: no staff group loses Paymos access on upgrade; the ACL turns on only once the permissions are granted', function () {
    $prev = Loader::$models;
    $prevSession = $_SESSION ?? null;
    $_SESSION['blesta_staff_id'] = 7;
    try {
        // Permissions not registered yet when upgrade() runs: nothing granted, nothing marked, no lockout.
        $acl = new FakeBlestaAcl();
        $acl->install();
        $acl->permissionsRegistered = false;
        $db = new InMemoryDbDriver();
        $plugin = new PaymosPlugin();
        $plugin->setDbDriver($db);
        $plugin->upgrade('1.0.4', 12);
        assertSame(false, StaffPermissions::isGranted(new CredentialStore($db)), 'Nothing was granted, so nothing may be marked.');

        AppController::$aclCheck = static fn (string $aco): bool => !empty($acl->allowed['staff_group_3|' . $aco]);
        $page = new AdminRefunds();
        $page->setDbDriver($db);
        $page->plugin = 'paymos';
        $page->controller = 'admin_refunds';
        $page->action = 'index';
        $page->preAction();
        assertSame(0, $page->requireLoginCalls, 'While the permissions cannot be granted the page keeps its earlier access.');

        // Blesta registered them later: the next admin request grants every staff group, then enforces.
        $acl->permissionsRegistered = true;
        $page2 = new AdminRefunds();
        $page2->setDbDriver($db);
        $page2->plugin = 'paymos';
        $page2->controller = 'admin_refunds';
        $page2->action = 'index';
        $page2->preAction();
        assertSame(array(array(3, 12), array(4, 12), array(5, 12)), $acl->grants, 'Every staff group that could open Paymos before keeps it.');
        assertSame(true, StaffPermissions::isGranted(new CredentialStore($db)));
        assertSame(1, $page2->requireLoginCalls);

        // Permissions registered before upgrade() (Blesta 5/6 order): granted during upgrade, once.
        $acl2 = new FakeBlestaAcl();
        $acl2->install();
        $db2 = new InMemoryDbDriver();
        $plugin2 = new PaymosPlugin();
        $plugin2->setDbDriver($db2);
        $plugin2->upgrade('1.0.4', 12);
        $plugin2->upgrade('1.0.5', 12);
        assertSame(array(array(3, 12), array(4, 12), array(5, 12)), $acl2->grants, 'Granted once; later upgrades keep staff group settings.');
    } finally {
        AppController::$aclCheck = null;
        Loader::$models = $prev;
        if ($prevSession === null) {
            unset($_SESSION['blesta_staff_id']);
        } else {
            $_SESSION = $prevSession;
        }
    }
});

runTest('9.3 R-plugins-03: each Paymos payment of an invoice is refunded on its own; none blocks another', function () {
    $db = new InMemoryDbDriver();
    $now = 1764200000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $payments = array(
        'inv_pay_A' => array('external_order_id' => 'blesta_inv_880_r0', 'platform_ref' => 'inv_880', 'blesta_invoice_id' => 880, 'amount' => '40', 'renewal_attempt' => 0, 'meta' => array(array('id' => 880, 'amount' => '40')), 'tx' => '9001'),
        'inv_pay_C' => array('external_order_id' => 'blesta_inv_880_r1', 'platform_ref' => 'inv_880', 'blesta_invoice_id' => 880, 'amount' => '60', 'renewal_attempt' => 1, 'meta' => array(array('id' => 880, 'amount' => '60')), 'tx' => '9003'),
        'inv_pay_B' => array('external_order_id' => 'blesta_inv_880_881_r0', 'platform_ref' => 'inv_880_881', 'blesta_invoice_id' => null, 'amount' => '50', 'renewal_attempt' => 0, 'meta' => array(array('id' => 880, 'amount' => '20'), array('id' => 881, 'amount' => '30')), 'tx' => '9002'),
    );
    $txs = array();
    foreach ($payments as $paymosId => $p) {
        seedSnapshot($snapshots, array(
            'external_order_id' => $p['external_order_id'],
            'paymos_invoice_id' => $paymosId,
            'platform_ref' => $p['platform_ref'],
            'blesta_invoice_id' => $p['blesta_invoice_id'],
            'client_id' => '90',
            'payload_meta' => json_encode(array('invoices' => $p['meta'])),
            'amount' => $p['amount'],
            'transaction_id' => '0x' . $paymosId,
            'status' => 'paid',
            'final_status' => 'paid',
            'renewal_attempt' => $p['renewal_attempt'],
        ));
        $txs[$p['tx']] = array('id' => $p['tx'], 'client_id' => '90', 'transaction_id' => '0x' . $paymosId, 'reference_id' => $paymosId, 'amount' => $p['amount'], 'currency' => 'USD', 'status' => 'approved', 'notes' => '');
    }
    $edits = array();
    $native = function (string $cmd, array $input) use (&$txs, &$edits, $snapshots, $payments): array {
        if ($cmd === 'snapshot') {
            $mapping = isset($input['paymos_invoice_id'])
                ? $snapshots->findByInvoiceId((string) $input['paymos_invoice_id'])
                : $snapshots->findPaidByBlestaInvoiceId((int) $input['invoice_id']);
            return array(
                'invoice' => array('id' => (int) $input['invoice_id'], 'client_id' => '90', 'status' => 'active', 'total' => '100', 'currency' => 'USD'),
                'transaction' => is_array($mapping) ? $txs[$payments[$mapping['paymos_invoice_id']]['tx']] : array(),
                'mapping' => $mapping,
            );
        }
        if ($cmd === 'marker') {
            $tx = $txs[(string) $input['transaction_id']] ?? null;
            return ($tx !== null && $tx['status'] === 'refunded' && strpos($tx['notes'], (string) $input['marker']) !== false) ? array($tx) : array();
        }
        if ($cmd === 'Transactions::edit') {
            $id = (string) $input['transaction_id'];
            $edits[] = $id;
            $txs[$id]['status'] = $input['vars']['status'];
            $txs[$id]['notes'] = $input['vars']['notes'];
            return array('result' => 'success');
        }
        return array();
    };
    $exts = array();
    $transport = new SpyTransport(function (array $req) use (&$exts, $payments): HttpResponse {
        $path = (string) parse_url($req['url'], PHP_URL_PATH);
        if ($req['method'] === 'POST' && $path === '/v1/refunds') {
            $body = json_decode((string) $req['body'], true);
            $exts[$body['invoice_id']] = $body['external_order_id'];
            return jsonResponse(201, array('withdrawal_id' => 'wd_' . $body['invoice_id'], 'status' => 'completed'));
        }
        if (strncmp($path, '/v1/refunds/wd_', 15) === 0) {
            $paymosId = substr($path, 15);
            return jsonResponse(200, array('withdrawal_id' => 'wd_' . $paymosId, 'invoice_id' => $paymosId, 'project_id' => 'prj_sandbox_blesta_001', 'is_test' => true, 'mode' => 'full', 'external_order_id' => $exts[$paymosId], 'status' => 'completed', 'amount' => $payments[$paymosId]['amount'], 'currency' => 'USDT', 'network' => 'arbitrum', 'first_sender' => '0xsender'));
        }
        if (strncmp($path, '/v1/invoices/inv_pay_', 21) === 0) {
            $paymosId = substr($path, 13);
            return jsonResponse(200, sandboxInvoice($paymosId, $payments[$paymosId]['external_order_id'], 'paid', $payments[$paymosId]['amount'], array('refund_summary' => array('can_refund_full' => true, 'can_refund_overpayment' => false, 'full_refund_amount' => $payments[$paymosId]['amount'], 'overpayment_refund_amount' => null))));
        }
        if ($path === '/v1/refunds') {
            return jsonResponse(200, array('items' => array(), 'next_cursor' => null));
        }
        throw new \RuntimeException('Unexpected request: ' . $req['method'] . ' ' . $path);
    });

    $controller = new AdminRefunds();
    $controller->setDbDriver($db);
    $controller->setClock($clock);
    $controller->setMeta(array('mode' => 'sandbox'));
    $controller->setClientFactory(static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport));
    $controller->setRefundNativeHandler($native);
    $controller->setStaffAuthenticator(static fn (): bool => true);
    $controller->setCsrfVerifier(static fn (string $t): bool => $t === 'csrf_ok');
    $byPayment = static function (array $panelBody): array {
        $out = array();
        foreach ($panelBody['payments'] ?? array() as $payment) {
            $out[$payment['paymos_invoice_id']] = $payment;
        }
        return $out;
    };
    $submit = static function (string $invoiceId, ?string $paymosInvoiceId = null) use ($controller): array {
        $post = array('_csrf_token' => 'csrf_ok', 'invoice_id' => $invoiceId, 'mode' => 'full', 'request_token' => CredentialStore::uuidV4());
        if ($paymosInvoiceId !== null) {
            $post['paymos_invoice_id'] = $paymosInvoiceId;
        }
        return $controller->handleSubmitRequest('POST', $post);
    };

    // #881 is covered only by B: the refund goes to B's transaction, not to A of invoice #880.
    $p881 = $byPayment($controller->handlePanelRequest(881, 'en_us')['body']);
    assertSame(array('inv_pay_B'), array_keys($p881));
    $resB = $submit('881');
    assertSame(200, $resB['http_status'], 'Refund of B failed: ' . json_encode($resB['body']));
    assertSame(array('9002'), $edits);

    // #880 has three payments; each is offered on its own.
    $p880 = $byPayment($controller->handlePanelRequest(880, 'en_us')['body']);
    assertSame(array('inv_pay_A', 'inv_pay_B', 'inv_pay_C'), (static function (array $k): array { sort($k); return $k; })(array_keys($p880)));
    assertSame(false, $p880['inv_pay_B']['can_refund_full'], 'B is already refunded.');
    assertSame(true, $p880['inv_pay_A']['can_refund_full']);
    assertSame(true, $p880['inv_pay_C']['can_refund_full']);

    $ambiguous = $submit('880');
    assertSame(400, $ambiguous['http_status'], 'With several payments the admin must pick one.');
    assertSame('refund_payment_selection_required', $ambiguous['body']['reason'] ?? null);

    $resC = $submit('880', 'inv_pay_C');
    assertSame(200, $resC['http_status'], 'Refund of C failed: ' . json_encode($resC['body']));
    assertSame(array('9002', '9003'), $edits);

    // The native Refund button of A's transaction still works after C was refunded in full.
    $gw = makeGateway($db, $transport, $clock);
    $gw->setRefundNativeHandler($native);
    $nativeA = $gw->refund('inv_pay_A', '0xinv_pay_A', '40');
    assertSame('refunded', $nativeA['status'] ?? null, 'A must stay refundable: ' . json_encode($gw->errors()));
    assertSame(array('9002', '9003', '9001'), $edits);
});

runTest('9.4 R-plugins-05: while Blesta has not recorded a paid Paymos invoice, the buyer gets the same checkout, not a second invoice', function () {
    $db = new InMemoryDbDriver();
    $store = new CredentialStore($db);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $snapshots = new SnapshotStore($db);
    $posts = 0;
    $transport = new SpyTransport(function (array $req) use (&$posts): HttpResponse {
        $posts++;
        $id = 'inv_wait_' . $posts;
        return jsonResponse(201, array('invoice_id' => $id, 'payment_url' => 'https://checkout.paymos.io/inv/' . $id, 'status' => 'awaiting_client'));
    });
    $known = false;
    $checkout = new CheckoutService(
        $config,
        $snapshots,
        static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport),
        null,
        null,
        null,
        static function (string $transactionId) use (&$known): ?bool {
            return $known;
        }
    );
    $first = $checkout->createCheckout('9', '40.00', 'USD', array(array('id' => 905, 'amount' => '40.00')));
    $snapshots->updateStatus($first['paymos_invoice_id'], 'paid', '0xwait905');

    $again = $checkout->createCheckout('9', '40.00', 'USD', array(array('id' => 905, 'amount' => '40.00')));
    assertSame(true, $again['reused'], 'The paid invoice is shown again while Blesta lacks its transaction.');
    assertSame($first['payment_url'], $again['payment_url']);
    assertSame(1, $posts);

    $known = true;
    $next = $checkout->createCheckout('9', '40.00', 'USD', array(array('id' => 905, 'amount' => '40.00')));
    assertSame('blesta_inv_905_r1', $next['external_order_id'], 'Once Blesta holds the payment the rest of the invoice is payable.');
    assertSame(2, $posts);
});

runTest('9.5 R-plugins-06: a later invoice.paid for a payment Blesta already holds is not handed over again', function () {
    $db = new InMemoryDbDriver();
    $now = 1764210000;
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, null, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $paid = sandboxInvoice('inv_known_910', 'blesta_inv_910_r0', 'paid', '25', array('payment' => array('tx_hash' => '0xknown910')));
    $transport = new SpyTransport(static fn (): HttpResponse => jsonResponse(200, $paid));
    foreach (array('known' => true, 'unknown' => null) as $case => $lookup) {
        $db->tables[SnapshotStore::TABLE] = array();
        seedSnapshot($snapshots, array(
            'external_order_id' => 'blesta_inv_910_r0',
            'paymos_invoice_id' => 'inv_known_910',
            'platform_ref' => 'inv_910',
            'blesta_invoice_id' => 910,
            'amount' => '25',
            'transaction_id' => '0xknown910',
            'status' => 'paid',
            'final_status' => 'paid',
        ));
        $processor = new CallbackProcessor(
            Config::fromStore(array('mode' => 'sandbox'), $credStore),
            $snapshots,
            new PdoEventStore($db, $clock),
            static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport),
            static fn (): array => array('paid' => false, 'amount' => '25', 'currency' => 'USD'),
            null,
            null,
            $clock,
            null,
            null,
            static fn (string $txId): ?bool => $lookup
        );
        $body = invoiceWebhook('evt_reorg_910_' . $case, 'invoice.paid', $paid, $now);
        $res = $processor->handle($body, signWebhook($body, 'whsec_sandbox_blesta_secret_001', $now));
        assertSame(200, $res['http_status']);
        assertSame(null, $res['transaction'], 'No replay when Blesta holds the transaction or it cannot be checked (' . $case . ').');
    }
    $meta = SnapshotStore::decodeMeta($snapshots->findByInvoiceId('inv_known_910')['payload_meta']);
    assertTrue(empty($meta['blesta_transaction_confirmed']), 'An unknown answer confirms nothing.');
});

runTest('9.6 R-plugins-07: a refused replay never overwrites a key another request already bound', function () {
    $db = new InMemoryDbDriver();
    $now = 1764220000;
    $clock = static fn (): int => $now;
    $store = new CredentialStore($db, null, $clock);
    $store->saveCredentials(sampleCredentials());
    $config = Config::fromStore(array('mode' => 'sandbox'), $store);
    $snapshots = new SnapshotStore($db, $clock);
    $step = 0;
    $transport = new SpyTransport(function (array $req) use (&$step, $db, $now): HttpResponse {
        $step++;
        if ($req['method'] === 'POST' && $step === 1) {
            throw new \RuntimeException('Paymos HTTP error: connection reset');
        }
        if ($req['method'] === 'POST') {
            // A parallel request (double click) bound the key while this replay was in flight.
            $db->tables[SnapshotStore::TABLE]['blesta_inv_915_r0']['paymos_invoice_id'] = 'inv_bound_915';
            $db->tables[SnapshotStore::TABLE]['blesta_inv_915_r0']['payment_url'] = 'https://checkout.paymos.io/inv/inv_bound_915';
            $db->tables[SnapshotStore::TABLE]['blesta_inv_915_r0']['status'] = 'awaiting_client';
            return new HttpResponse(409, (string) json_encode(array('type' => 'about:blank', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Conflict.', 'code' => 'invoice_idempotency_conflict')), array('Content-Type' => 'application/problem+json'));
        }
        return jsonResponse(200, sandboxInvoice('inv_bound_915', 'blesta_inv_915_r0', 'awaiting_client', '30', array('expires_at' => $now + 1200)));
    });
    $checkout = new CheckoutService($config, $snapshots, static fn (Config $c, string $e): Client => new Client($c->clientConfig($e), $transport), null, $clock);
    assertThrows(\RuntimeException::class, static fn () => $checkout->createCheckout('9', '30.00', 'USD', array(array('id' => 915, 'amount' => '30.00'))));
    $res = $checkout->createCheckout('9', '30.00', 'USD', array(array('id' => 915, 'amount' => '30.00')));
    $row = $snapshots->findByExternalOrderId('blesta_inv_915_r0');
    assertSame('inv_bound_915', $row['paymos_invoice_id'], 'The binding of the parallel request must survive.');
    assertSame('awaiting_client', $row['status']);
    assertSame('inv_bound_915', $res['paymos_invoice_id']);
});

runTest('9.7 R-plugins-08: a staff group without the permission gets JSON 403 on JSON endpoints, not a redirect', function () {
    $prev = Loader::$models;
    $prevSession = $_SESSION ?? null;
    $_SESSION['blesta_staff_id'] = 8;
    try {
        $acl = new FakeBlestaAcl();
        $acl->install();
        $db = new InMemoryDbDriver();
        StaffPermissions::markGranted(new CredentialStore($db));

        $refunds = new AdminRefunds();
        $refunds->setDbDriver($db);
        $refunds->plugin = 'paymos';
        $refunds->controller = 'admin_refunds';
        $refunds->action = 'submit';
        $refunds->setCsrfVerifier(static fn (string $t): bool => true);
        $refunds->preAction();
        assertSame(0, $refunds->requireLoginCalls, 'JSON endpoints must not answer with the Blesta login redirect.');
        $denied = $refunds->handleSubmitRequest('POST', array('_csrf_token' => 'ok', 'invoice_id' => '1', 'mode' => 'full', 'request_token' => CredentialStore::uuidV4()));
        assertSame(403, $denied['http_status']);
        assertSame('paymos_admin_access_denied', $denied['body']['reason'] ?? null);

        $connect = new AdminConnect();
        $connect->setDbDriver($db);
        $connect->plugin = 'paymos';
        $connect->controller = 'admin_connect';
        $connect->action = 'start';
        $connect->setCsrfVerifier(static fn (string $t): bool => true);
        $connect->preAction();
        assertSame(0, $connect->requireLoginCalls);
        $deniedConnect = $connect->handleRequest('POST', array('action' => 'invalid', '_csrf_token' => 'ok'));
        assertSame(403, $deniedConnect['http_status']);
        assertSame('paymos_admin_access_denied', $deniedConnect['body']['reason'] ?? null);

        // A permitted group passes the same check.
        $acl->allowed['staff_group_4|paymos.admin_connect'] = true;
        $allowedConnect = $connect->handleRequest('POST', array('action' => 'invalid', '_csrf_token' => 'ok'));
        assertSame(400, $allowedConnect['http_status'], 'Granted staff reach the action itself.');
    } finally {
        Loader::$models = $prev;
        if ($prevSession === null) {
            unset($_SESSION['blesta_staff_id']);
        } else {
            $_SESSION = $prevSession;
        }
    }
});

echo "\nBlesta test summary: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
