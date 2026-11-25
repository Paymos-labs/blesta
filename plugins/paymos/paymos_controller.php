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

    public function preAction(): void
    {
        if (is_callable(array('parent', 'preAction'))) {
            parent::preAction();
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

        if (class_exists('Form') && method_exists('Form', 'verifyCsrfToken')) {
            if (\Form::verifyCsrfToken($provided) !== true) {
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
        if (class_exists('Form') && method_exists('Form', 'getCsrfToken')) {
            $token = \Form::getCsrfToken();
            if (is_scalar($token) && trim((string) $token) !== '') {
                return trim((string) $token);
            }
        }
        if (isset($_SESSION['_csrf_token']) && is_scalar($_SESSION['_csrf_token'])) {
            return trim((string) $_SESSION['_csrf_token']);
        }
        return '';
    }

    protected function resolveAdminRoutePrefix(): string
    {
        $adminRoute = '/admin/';
        if (class_exists('Configure') && method_exists('Configure', 'get')) {
            $route = \Configure::get('Route.admin');
            if (is_scalar($route) && trim((string) $route) !== '') {
                $adminRoute = '/' . trim((string) $route, '/') . '/';
            }
        }
        return $adminRoute;
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
