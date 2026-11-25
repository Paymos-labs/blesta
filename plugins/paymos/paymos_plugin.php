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

use Paymos\Client;
use PaymosBlesta\CallbackProcessor;
use PaymosBlesta\Config;
use PaymosBlesta\CredentialStore;
use PaymosBlesta\DbDriverInterface;
use PaymosBlesta\PdoDbDriver;
use PaymosBlesta\PdoEventStore;
use PaymosBlesta\Reconciler;
use PaymosBlesta\RefundAdapter;
use PaymosBlesta\RefundIntentStore;
use PaymosBlesta\SnapshotStore;

/**
 * Companion Blesta Plugin for Paymos:
 * - 10-minute cron task running invoice Reconciler (24h window, 600s throttle) + RefundAdapter::run()
 * - Appcontroller.structure event hook for admin Paymos Refunds navigation helper
 * - Hosts AdminConnect & AdminRefunds controllers
 */
class PaymosPlugin extends Plugin
{
    public const CRON_KEY = 'paymos_reconcile';

    /** @var DbDriverInterface|null */
    private $dbDriver;

    /** @var callable|null */
    private $clientFactory;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $keyMaterialProvider;

    /** @var callable|null */
    private $billingStateResolver;

    /** @var callable|null */
    private $paymentApplier;

    /** @var callable|null */
    private $refundNativeHandler;

    /** @var array<string, mixed>|null */
    private $metaOverride;

    /** @var array<int, array{event: string, level: string, context: array<string, mixed>}> */
    private $logs = array();

    public function __construct()
    {
        $this->loadConfig(__DIR__ . DIRECTORY_SEPARATOR . 'config.json');
        if (class_exists('Language') && method_exists('Language', 'loadLang')) {
            Language::loadLang('paymos_plugin', null, __DIR__ . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR);
        }
    }

    public function setDbDriver(DbDriverInterface $driver): void
    {
        $this->dbDriver = $driver;
    }

    public function setClientFactory(callable $factory): void
    {
        $this->clientFactory = $factory;
    }

    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
    }

    public function setKeyMaterialProvider(callable $provider): void
    {
        $this->keyMaterialProvider = $provider;
    }

    public function setBillingStateResolver(callable $resolver): void
    {
        $this->billingStateResolver = $resolver;
    }

    public function setPaymentApplier(callable $applier): void
    {
        $this->paymentApplier = $applier;
    }

    public function setRefundNativeHandler(callable $handler): void
    {
        $this->refundNativeHandler = $handler;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function setMeta(array $meta): void
    {
        $this->metaOverride = $meta;
    }

    /**
     * @return array<int, array{event: string, level: string, context: array<string, mixed>}>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * @param int $plugin_id
     */
    public function install($plugin_id): void
    {
        try {
            $driver = $this->resolveDbDriver();
            $credStore = new CredentialStore($driver, $this->keyMaterialProvider, $this->clock);
            $credStore->ensureSchema();
            (new PdoEventStore($driver, $this->clock))->ensureSchema();
            (new SnapshotStore($driver, $this->clock))->ensureSchema();
            (new RefundIntentStore($driver, $credStore, $this->clock))->ensureSchema();
        } catch (\Throwable $e) {
            $this->log('plugin.install_schema_error', array('error' => $e->getMessage()), 'warning');
        }

        $this->registerCronTask();
    }

    /**
     * @param int $plugin_id
     * @param bool $last_instance
     */
    public function uninstall($plugin_id, $last_instance): void
    {
        $this->unregisterCronTask((bool) $last_instance);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActions(): array
    {
        return array(
            array(
                'action' => 'nav_secondary_staff',
                'uri' => 'plugin/paymos/admin_refunds/',
                'name' => 'PaymosPlugin.nav_secondary_staff.refunds',
                'options' => array('parent' => 'billing/'),
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(): array
    {
        return array(
            array(
                'event' => 'Appcontroller.structure',
                'callback' => array('this', 'injectAdminHelpers'),
            ),
        );
    }

    /**
     * Appcontroller.structure event hook: injects a non-intrusive Paymos Refunds helper
     * strictly on admin billing pages (never exposes secrets, never runs on client portal).
     *
     * @param object $event
     */
    public function injectAdminHelpers($event): void
    {
        if (!is_object($event) || !method_exists($event, 'getParams') || !method_exists($event, 'getReturnValue')) {
            return;
        }

        $params = $event->getParams();
        if (!is_array($params) || ($params['portal'] ?? '') !== 'admin') {
            return;
        }

        $controller = strtolower((string) ($params['controller'] ?? ''));
        if ($controller !== 'admin_clients' && $controller !== 'admin_billing') {
            return;
        }

        $returnVal = $event->getReturnValue();
        if (!is_array($returnVal)) {
            $returnVal = array();
        }

        $adminRoute = '/admin/';
        if (class_exists('Configure') && method_exists('Configure', 'get')) {
            $route = \Configure::get('Route.admin');
            if (is_scalar($route) && trim((string) $route) !== '') {
                $adminRoute = '/' . trim((string) $route, '/') . '/';
            }
        }

        $refundsUrl = htmlspecialchars($adminRoute . 'plugin/paymos/admin_refunds/', ENT_QUOTES, 'UTF-8');
        $submitUrl = htmlspecialchars($adminRoute . 'plugin/paymos/admin_refunds/submit/', ENT_QUOTES, 'UTF-8');
        $snippet = '<meta name="paymos-refunds-url" content="' . $refundsUrl . '" />'
            . '<meta name="paymos-refunds-submit-url" content="' . $submitUrl . '" />';
        $existingHead = isset($returnVal['head']) && is_array($returnVal['head']) ? $returnVal['head'] : array();
        $existingHead[] = $snippet;
        $returnVal['head'] = $existingHead;

        if (method_exists($event, 'setReturnValue')) {
            $event->setReturnValue($returnVal);
        }
    }

    /**
     * Execute Paymos scheduled reconciliation (unpaid 24h invoices + pending/external refunds).
     *
     * @param string $key
     * @return array{reconcile: ?array<string, mixed>, refunds_ran: bool}
     */
    public function cron($key): array
    {
        $summary = array('reconcile' => null, 'refunds_ran' => false);
        if ((string) $key !== self::CRON_KEY) {
            return $summary;
        }

        $driver = $this->resolveDbDriver();
        $credentialStore = new CredentialStore($driver, $this->keyMaterialProvider, $this->clock);

        try {
            $config = Config::fromStore($this->loadGatewayMeta(), $credentialStore);
        } catch (\Throwable $e) {
            $this->log('cron.not_connected', array('error' => Config::sanitizeStatic($e->getMessage())), 'info');
            return $summary;
        }

        $snapshots = new SnapshotStore($driver, $this->clock);
        $eventStore = new PdoEventStore($driver, $this->clock);
        $intentStore = new RefundIntentStore($driver, $credentialStore, $this->clock);

        $clientFactory = $this->clientFactory ?: static function (Config $cfg, string $environment): Client {
            return new Client($cfg->clientConfig($environment));
        };
        $logger = function (string $event, array $context, string $level): void {
            $this->log($event, $context, $level);
        };

        $refundAdapter = new RefundAdapter(
            $config,
            $credentialStore,
            $snapshots,
            $intentStore,
            $clientFactory,
            $this->refundNativeHandler,
            null,
            $this->clock,
            $logger
        );

        try {
            $callbackProcessor = new CallbackProcessor(
                $config,
                $snapshots,
                $eventStore,
                $clientFactory,
                $this->resolveBillingStateResolver(),
                static function ($event, string $environment) use ($refundAdapter): void {
                    $refundAdapter->callback($event, $environment);
                },
                $this->resolvePaymentApplier(),
                $this->clock,
                $logger
            );

            $reconciler = new Reconciler(
                $config,
                $credentialStore,
                $snapshots,
                $callbackProcessor,
                $clientFactory,
                $this->clock,
                $logger
            );
            $summary['reconcile'] = $reconciler->run();
        } catch (\Throwable $e) {
            $this->log('cron.reconcile_failed', array('error' => $config->sanitize($e->getMessage())), 'error');
        }

        try {
            $refundAdapter->run();
            $summary['refunds_ran'] = true;
        } catch (\Throwable $e) {
            $this->log('cron.refunds_failed', array('error' => $config->sanitize($e->getMessage())), 'error');
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadGatewayMeta(): array
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
                // Fall back to default sandbox meta.
            }
        }

        return Config::sanitizeMetaForStorage(array());
    }

    private function resolveBillingStateResolver(): callable
    {
        if ($this->billingStateResolver !== null) {
            return $this->billingStateResolver;
        }

        return static function (array $snapshot): array {
            if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                return array();
            }

            $meta = SnapshotStore::decodeMeta(isset($snapshot['payload_meta']) ? (string) $snapshot['payload_meta'] : null);
            $invoiceId = 0;
            if (isset($snapshot['blesta_invoice_id']) && is_numeric($snapshot['blesta_invoice_id']) && (int) $snapshot['blesta_invoice_id'] > 0) {
                $invoiceId = (int) $snapshot['blesta_invoice_id'];
            } elseif (isset($meta['blesta_invoice_id']) && is_numeric($meta['blesta_invoice_id']) && (int) $meta['blesta_invoice_id'] > 0) {
                $invoiceId = (int) $meta['blesta_invoice_id'];
            } elseif (isset($meta['invoices'][0]['id']) && is_numeric($meta['invoices'][0]['id']) && (int) $meta['invoices'][0]['id'] > 0) {
                $invoiceId = (int) $meta['invoices'][0]['id'];
            } elseif (isset($snapshot['platform_ref']) && is_string($snapshot['platform_ref'])
                && preg_match('/\Ainv_([1-9][0-9]*)\z/', $snapshot['platform_ref'], $m) === 1) {
                $invoiceId = (int) $m[1];
            }

            if ($invoiceId <= 0) {
                return array();
            }

            $host = new \stdClass();
            \Loader::loadModels($host, array('Invoices'));
            if (!isset($host->Invoices) || !method_exists($host->Invoices, 'get')) {
                return array();
            }

            $inv = $host->Invoices->get($invoiceId);
            if ($inv === false || $inv === null) {
                return array('deleted' => true, 'paid' => false);
            }

            $status = strtolower(trim((string) ($inv->status ?? '')));
            $dateClosed = isset($inv->date_closed) ? trim((string) $inv->date_closed) : '';
            $due = isset($inv->due) && is_numeric($inv->due) ? (float) $inv->due : null;
            $paid = $dateClosed !== ''
                || $status === 'paid'
                || ($due !== null && $due <= 0.000001 && isset($inv->paid) && is_numeric($inv->paid) && (float) $inv->paid > 0.0);

            $amount = '';
            if (isset($inv->total) && is_scalar($inv->total) && trim((string) $inv->total) !== '') {
                $amount = trim((string) $inv->total);
            } elseif (isset($inv->due) && is_scalar($inv->due) && trim((string) $inv->due) !== '') {
                $amount = trim((string) $inv->due);
            }
            $currency = isset($inv->currency) && is_scalar($inv->currency)
                ? strtoupper(trim((string) $inv->currency))
                : '';

            return array(
                'deleted' => false,
                'paid' => $paid,
                'amount' => $amount,
                'currency' => $currency,
            );
        };
    }

    private function resolvePaymentApplier(): callable
    {
        if ($this->paymentApplier !== null) {
            return $this->paymentApplier;
        }

        return static function (array $transaction, array $snapshot): void {
            if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                return;
            }

            $host = new \stdClass();
            \Loader::loadModels($host, array('Transactions', 'GatewayManager'));
            if (!isset($host->Transactions) || !method_exists($host->Transactions, 'add')) {
                return;
            }

            $gatewayId = null;
            if (isset($host->GatewayManager) && method_exists($host->GatewayManager, 'getByClass')) {
                $companyId = class_exists('Configure') && method_exists('Configure', 'get')
                    ? (int) (\Configure::get('Blesta.company_id') ?: 1)
                    : 1;
                $gateways = $host->GatewayManager->getByClass('paymos', $companyId);
                if (is_array($gateways) && isset($gateways[0]->id) && is_numeric($gateways[0]->id)) {
                    $gatewayId = (int) $gateways[0]->id;
                }
            }

            $txVars = array(
                'client_id' => $transaction['client_id'],
                'amount' => $transaction['amount'],
                'currency' => $transaction['currency'],
                'type' => 'other',
                'transaction_id' => $transaction['transaction_id'],
                'reference_id' => $transaction['reference_id'],
                'status' => 'approved',
            );
            if ($gatewayId !== null && $gatewayId > 0) {
                $txVars['gateway_id'] = $gatewayId;
            }

            $txId = $host->Transactions->add($txVars);

            if ($txId && method_exists($host->Transactions, 'apply') && !empty($transaction['invoices'])) {
                $amounts = array();
                foreach ($transaction['invoices'] as $inv) {
                    if (isset($inv['id'], $inv['amount'])) {
                        $amounts[] = array('invoice_id' => (int) $inv['id'], 'amount' => (string) $inv['amount']);
                    }
                }
                if (count($amounts) > 0) {
                    $host->Transactions->apply($txId, array('amounts' => $amounts));
                }
            }
        };
    }

    private function resolveDbDriver(): DbDriverInterface
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

    private function registerCronTask(): void
    {
        if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
            return;
        }

        try {
            $host = new \stdClass();
            \Loader::loadModels($host, array('CronTasks'));
            if (!isset($host->CronTasks) || !method_exists($host->CronTasks, 'getByKey')) {
                return;
            }

            $task = $host->CronTasks->getByKey(self::CRON_KEY, 'paymos', 'plugin');
            if (!$task) {
                $taskId = $host->CronTasks->add(array(
                    'key' => self::CRON_KEY,
                    'plugin_dir' => 'paymos',
                    'name' => 'PaymosPlugin.cron.reconcile_name',
                    'description' => 'PaymosPlugin.cron.reconcile_desc',
                    'type' => 'interval',
                ));
            } else {
                $taskId = is_object($task) ? ($task->id ?? null) : null;
            }

            if ($taskId && method_exists($host->CronTasks, 'addTaskRun')) {
                $host->CronTasks->addTaskRun($taskId, array(
                    'enabled' => 1,
                    'interval' => 10,
                ));
            }
        } catch (\Throwable $e) {
            $this->log('plugin.cron_register_error', array('error' => $e->getMessage()), 'warning');
        }
    }

    private function unregisterCronTask(bool $lastInstance): void
    {
        if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
            return;
        }

        try {
            $host = new \stdClass();
            \Loader::loadModels($host, array('CronTasks'));
            if (!isset($host->CronTasks) || !method_exists($host->CronTasks, 'getByKey')) {
                return;
            }

            $task = $host->CronTasks->getByKey(self::CRON_KEY, 'paymos', 'plugin');
            if (is_object($task) && isset($task->id)) {
                if (method_exists($host->CronTasks, 'deleteTaskRun') && isset($task->task_run_id)) {
                    $host->CronTasks->deleteTaskRun($task->task_run_id);
                }
                if ($lastInstance && method_exists($host->CronTasks, 'deleteTask')) {
                    $host->CronTasks->deleteTask($task->id, 'plugin', 'paymos');
                }
            }
        } catch (\Throwable $e) {
            $this->log('plugin.cron_unregister_error', array('error' => $e->getMessage()), 'warning');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $event, array $context, string $level = 'info'): void
    {
        $this->logs[] = array(
            'event' => $event,
            'level' => $level,
            'context' => $context,
        );
    }
}
