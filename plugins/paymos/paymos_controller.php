<?php

declare(strict_types=1);

$paymosGatewayAutoloader = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'components'
    . DIRECTORY_SEPARATOR . 'gateways'
    . DIRECTORY_SEPARATOR . 'nonmerchant'
    . DIRECTORY_SEPARATOR . 'paymos'
    . DIRECTORY_SEPARATOR . 'src'
    . DIRECTORY_SEPARATOR . 'Autoloader.php';

if (is_file($paymosGatewayAutoloader)) {
    require_once $paymosGatewayAutoloader;
    \PaymosBlesta\Autoloader::register();
}

use PaymosBlesta\Config;
use PaymosBlesta\CredentialStore;
use PaymosBlesta\DbDriverInterface;
use PaymosBlesta\PdoDbDriver;
use PaymosBlesta\StaffPermissions;

/**
 * Base Blesta plugin controller for Paymos admin endpoints (AdminConnect, AdminRefunds).
 */
class PaymosController extends AppController
{
    /** @var DbDriverInterface|null */
    protected $dbDriver;

    /** @var callable|null */
    protected $staffAuthenticator;

    /** @var callable|null */
    protected $csrfVerifier;

    /** @var callable|null */
    protected $keyMaterialProvider;

    /** @var callable|null */
    protected $clock;

    /** @var array<string, mixed>|null */
    protected $metaOverride;

    /** @var bool|null */
    private $staffPermissionsActive;

    public function preAction(): void
    {
        if (isset($this->structure) && is_object($this->structure)
            && method_exists($this->structure, 'setDefaultView') && defined('APPDIR')) {
            $this->structure->setDefaultView(APPDIR);
        }
        $parentClass = get_parent_class($this);
        if (is_string($parentClass) && method_exists($parentClass, 'preAction')) {
            parent::preAction();
        }
        // Staff login + ACL on HTML pages: Blesta checks the staff group against the plugin
        // permissions paymos.admin_connect / paymos.admin_refunds. JSON endpoints check the same
        // permission in requireStaffAuth() and answer JSON 403 instead of a login redirect.
        if (method_exists($this, 'requireLogin') && !$this->respondsWithJson() && $this->staffPermissionsActive()) {
            $this->requireLogin();
        }
        if (isset($this->view) && is_object($this->view)) {
            $this->view->view = 'default';
        }
        if (isset($this->structure) && is_object($this->structure)) {
            if (method_exists($this->structure, 'setDefaultView') && defined('APPDIR')) {
                $this->structure->setDefaultView(APPDIR);
            }
            if (isset($this->structure->view) && method_exists($this->structure, 'setView')) {
                $this->structure->setView(null, $this->structure->view);
            }
        }
        if (class_exists('Language') && method_exists('Language', 'loadLang')) {
            Language::loadLang('paymos_plugin', null, __DIR__ . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR);
        }
    }

    public function setDbDriver(DbDriverInterface $driver): void
    {
        $this->dbDriver = $driver;
    }

    public function setStaffAuthenticator(callable $authenticator): void
    {
        $this->staffAuthenticator = $authenticator;
    }

    public function setCsrfVerifier(callable $verifier): void
    {
        $this->csrfVerifier = $verifier;
    }

    public function setKeyMaterialProvider(callable $provider): void
    {
        $this->keyMaterialProvider = $provider;
    }

    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function setMeta(array $meta): void
    {
        $this->metaOverride = $meta;
    }

    protected function requireStaffAuth(): void
    {
        if ($this->staffAuthenticator !== null) {
            if (call_user_func($this->staffAuthenticator) !== true) {
                throw new \RuntimeException('paymos_admin_access_denied', 403);
            }
            return;
        }

        $staffId = null;
        if (isset($this->Session) && is_object($this->Session) && method_exists($this->Session, 'read')) {
            $staffId = $this->Session->read('blesta_staff_id');
        } elseif (isset($_SESSION['blesta_staff_id'])) {
            $staffId = $_SESSION['blesta_staff_id'];
        }

        if (!is_numeric($staffId) || (int) $staffId <= 0) {
            throw new \RuntimeException('paymos_admin_access_denied', 403);
        }

        if ($this->staffPermissionsActive() && !StaffPermissions::staffMayUse((int) $staffId, $this->aclAlias())) {
            throw new \RuntimeException('paymos_admin_access_denied', 403);
        }
    }

    /**
     * Whether the current action answers JSON (fetch() callers expect JSON, not a redirect).
     */
    protected function respondsWithJson(): bool
    {
        return false;
    }

    /**
     * Blesta ACO of this controller: plugin_dir.controller_file.
     */
    protected function aclAlias(): string
    {
        $controller = isset($this->controller) && is_string($this->controller) && $this->controller !== ''
            ? $this->controller
            : strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', static::class));

        return StaffPermissions::PLUGIN_DIR . '.' . $controller;
    }

    /**
     * The staff permissions are enforced only after they were granted (see StaffPermissions):
     * until then the pages keep the access staff had before the permissions existed.
     */
    protected function staffPermissionsActive(): bool
    {
        if ($this->staffPermissionsActive !== null) {
            return $this->staffPermissionsActive;
        }

        try {
            $this->staffPermissionsActive = StaffPermissions::grantToAllStaffGroups($this->createCredentialStore());
        } catch (\Throwable $e) {
            $this->staffPermissionsActive = false;
        }

        return $this->staffPermissionsActive;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $headers
     */
    protected function requireCsrfToken(array $post, array $headers = array()): void
    {
        $provided = '';
        if (isset($post['_csrf_token']) && is_scalar($post['_csrf_token'])) {
            $provided = trim((string) $post['_csrf_token']);
        } else {
            foreach ($headers as $name => $value) {
                $normalized = strtolower(str_replace('_', '-', (string) $name));
                if (($normalized === 'x-csrf-token' || $normalized === 'http-x-csrf-token') && is_scalar($value)) {
                    $provided = trim((string) $value);
                    break;
                }
            }
        }

        if ($provided === '') {
            throw new \RuntimeException('paymos_csrf_invalid', 403);
        }

        if ($this->csrfVerifier !== null) {
            if (call_user_func($this->csrfVerifier, $provided) !== true) {
                throw new \RuntimeException('paymos_csrf_invalid', 403);
            }
            return;
        }

        if (isset($this->Form) && is_object($this->Form) && method_exists($this->Form, 'verifyCsrfToken')) {
            $ref = new \ReflectionMethod($this->Form, 'verifyCsrfToken');
            $valid = $ref->getNumberOfParameters() >= 2
                ? $this->Form->verifyCsrfToken(null, $provided)
                : $this->Form->verifyCsrfToken($provided);
            if ($valid !== true) {
                throw new \RuntimeException('paymos_csrf_invalid', 403);
            }
            return;
        }

        if (class_exists('Form') && method_exists('Form', 'verifyCsrfToken')) {
            $ref = new \ReflectionMethod('Form', 'verifyCsrfToken');
            if ($ref->isStatic()) {
                $valid = $ref->getNumberOfParameters() >= 2
                    ? \Form::verifyCsrfToken(null, $provided)
                    : \Form::verifyCsrfToken($provided);
            } else {
                $form = new \Form();
                $valid = $ref->getNumberOfParameters() >= 2
                    ? $form->verifyCsrfToken(null, $provided)
                    : $form->verifyCsrfToken($provided);
            }
            if ($valid !== true) {
                throw new \RuntimeException('paymos_csrf_invalid', 403);
            }
            return;
        }

        $expected = '';
        if (isset($_SESSION['_csrf_token']) && is_scalar($_SESSION['_csrf_token'])) {
            $expected = trim((string) $_SESSION['_csrf_token']);
        }

        if ($expected === '' || !hash_equals($expected, $provided)) {
            throw new \RuntimeException('paymos_csrf_invalid', 403);
        }
    }

    protected function resolveCsrfToken(): string
    {
        if (isset($this->Form) && is_object($this->Form) && method_exists($this->Form, 'getCsrfToken')) {
            try {
                $token = $this->Form->getCsrfToken();
                if (is_scalar($token) && trim((string) $token) !== '') {
                    return trim((string) $token);
                }
            } catch (\Throwable $e) {
                // Fall through to Form helper fallback.
            }
        }

        if (class_exists('Form') && method_exists('Form', 'getCsrfToken')) {
            try {
                $ref = new \ReflectionMethod('Form', 'getCsrfToken');
                $token = $ref->isStatic() ? \Form::getCsrfToken() : (new \Form())->getCsrfToken();
                if (is_scalar($token) && trim((string) $token) !== '') {
                    return trim((string) $token);
                }
            } catch (\Throwable $e) {
                // Fall through to session fallback.
            }
        }
        if (isset($_SESSION['_csrf_token']) && is_scalar($_SESSION['_csrf_token'])) {
            return trim((string) $_SESSION['_csrf_token']);
        }
        return '';
    }

    /**
     * Admin base URI including the Blesta web directory (subfolder installs, no mod_rewrite).
     */
    protected function resolveAdminRoutePrefix(): string
    {
        if (isset($this->base_uri) && is_string($this->base_uri) && trim($this->base_uri) !== '') {
            return rtrim(trim($this->base_uri), '/') . '/';
        }

        return Config::adminUri('', defined('WEBDIR') ? (string) WEBDIR : null);
    }

    /**
     * JSON error body for an access/CSRF/method failure: translated text plus the machine reason.
     *
     * @return array{error: string, reason: string}
     */
    protected function adminErrorBody(string $reason): array
    {
        $keys = array(
            'paymos_admin_access_denied' => 'PaymosPlugin.error.access_denied',
            'paymos_csrf_invalid' => 'PaymosPlugin.error.csrf_invalid',
            'paymos_method_not_allowed' => 'PaymosPlugin.error.method_not_allowed',
            'paymos_invalid_action' => 'PaymosPlugin.error.invalid_action',
        );

        return array(
            'error' => isset($keys[$reason]) ? self::pluginText($keys[$reason]) : self::pluginText('PaymosPlugin.error.access_denied'),
            'reason' => $reason,
        );
    }

    protected static function pluginText(string $key): string
    {
        static $loaded = false;
        if (!$loaded && class_exists('Language') && method_exists('Language', 'loadLang')) {
            Language::loadLang('paymos_plugin', null, __DIR__ . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR);
            $loaded = true;
        }

        return class_exists('Language') && method_exists('Language', '_') ? (string) Language::_($key, true) : $key;
    }

    protected function resolveBlestaBaseUrl(?string $override = null): string
    {
        if ($override !== null && trim($override) !== '') {
            return rtrim(trim($override), '/');
        }

        if (class_exists('Configure') && method_exists('Configure', 'get')) {
            $rawCallback = \Configure::get('Blesta.gw_callback_url');
            if (is_scalar($rawCallback)) {
                $trimmed = rtrim(trim((string) $rawCallback), '/');
                if ($trimmed !== '') {
                    $origin = preg_replace('#/callback/gw(?:/.*)?\z#i', '', $trimmed);
                    if (is_string($origin) && $origin !== '') {
                        return rtrim($origin, '/');
                    }
                }
            }
        }

        $host = isset($_SERVER['HTTP_HOST']) && is_scalar($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
        if ($host !== '') {
            $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
            return ($https ? 'https://' : 'http://') . $host;
        }

        return '';
    }

    protected function resolveDbDriver(): DbDriverInterface
    {
        if ($this->dbDriver !== null) {
            return $this->dbDriver;
        }

        if (class_exists('Loader') && method_exists('Loader', 'loadComponents')) {
            $host = new \stdClass();
            \Loader::loadComponents($host, array('Record'));
            if (isset($host->Record) && is_object($host->Record) && method_exists($host->Record, 'connect')) {
                $pdo = $host->Record->connect();
                if ($pdo instanceof \PDO) {
                    $this->dbDriver = new PdoDbDriver($pdo);
                    return $this->dbDriver;
                }
            }
        }

        throw new \RuntimeException('Blesta database driver is unavailable.');
    }

    protected function createCredentialStore(): CredentialStore
    {
        return new CredentialStore(
            $this->resolveDbDriver(),
            $this->keyMaterialProvider,
            $this->clock
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadGatewayMeta(): array
    {
        if ($this->metaOverride !== null) {
            return Config::sanitizeMetaForStorage($this->metaOverride);
        }

        if (class_exists('Loader') && method_exists('Loader', 'loadModels')) {
            try {
                $host = new \stdClass();
                \Loader::loadModels($host, array('GatewayManager'));
                if (isset($host->GatewayManager) && method_exists($host->GatewayManager, 'getByClass')) {
                    $companyId = class_exists('Configure') && method_exists('Configure', 'get')
                        ? (int) (\Configure::get('Blesta.company_id') ?: 1)
                        : 1;
                    $gateways = $host->GatewayManager->getByClass('paymos', $companyId);
                    if (is_array($gateways) && isset($gateways[0]->meta) && is_array($gateways[0]->meta)) {
                        $meta = array();
                        foreach ($gateways[0]->meta as $item) {
                            if (is_object($item) && isset($item->key, $item->value)) {
                                $meta[(string) $item->key] = (string) $item->value;
                            }
                        }
                        return Config::sanitizeMetaForStorage($meta);
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to defaults.
            }
        }

        return Config::sanitizeMetaForStorage(array());
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function emitJsonResponse(int $status, array $payload): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
