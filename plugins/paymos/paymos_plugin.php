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
use PaymosBlesta\BillingState;
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
use PaymosBlesta\StaffPermissions;

/**
 * Companion Blesta Plugin for Paymos:
 * - 10-minute cron task running invoice Reconciler (24h window, 600s throttle) + RefundAdapter::run()
 * - Hosts AdminConnect & AdminRefunds controllers, gated by Blesta staff permissions
 */
class PaymosPlugin extends Plugin
{
    public const CRON_KEY = 'paymos_reconcile';
    private const PLUGIN_DIR = 'paymos';
    private const CRON_INTERVAL_MINUTES = 10;
    private const PERMISSION_GROUP = 'paymos.admin_main';

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

        // PluginManager::add() grants the installing admin's staff group every permission of
        // getPermissions() right after install(); the controllers may enforce them at once.
        $this->rememberPermissionsGranted();
    }

    /**
     * @param string $current_version
     * @param int $plugin_id
     */
    public function upgrade($current_version, $plugin_id): void
    {
        $this->registerCronTask();
        $this->grantExistingStaffGroups((int) $plugin_id);
        $this->removeLegacyEvent((int) $plugin_id);
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
     * Staff permission section "Paymos" in Staff > Groups.
     *
     * @return array<int, array<string, string>>
     */
    public function getPermissionGroups(): array
    {
        return array(
            array(
                'name' => Language::_('PaymosPlugin.permission.admin_main', true),
                'level' => 'staff',
                'alias' => self::PERMISSION_GROUP,
            ),
        );
    }

    /**
     * Connect (credentials of the whole installation) and Refunds (money back to buyers) are
     * reachable only by staff groups granted these permissions; requireLogin() enforces them.
     *
     * @return array<int, array<string, string>>
     */
    public function getPermissions(): array
    {
        return array(
            array(
                'group_alias' => self::PERMISSION_GROUP,
                'name' => Language::_('PaymosPlugin.permission.admin_connect', true),
                'alias' => self::PLUGIN_DIR . '.admin_connect',
                'action' => '*',
            ),
            array(
                'group_alias' => self::PERMISSION_GROUP,
                'name' => Language::_('PaymosPlugin.permission.admin_refunds', true),
                'alias' => self::PLUGIN_DIR . '.admin_refunds',
                'action' => '*',
            ),
        );
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
     * No event hooks. Returned explicitly so an upgrade drops the old Appcontroller.structure
     * registration (Blesta overwrites a plugin's events with this list on install and upgrade).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(): array
    {
        return array();
    }

    /**
     * No-op kept for installs of release 1.0.0: their plugin_events row still names this method
     * as the Appcontroller.structure handler until the admin runs Upgrade, and Blesta must not
     * call a missing method on every page in the meantime.
     *
     * @param object $event
     */
    public function injectAdminHelpers($event): void
    {
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
                $logger,
                CallbackProcessor::blestaClientNoteWriter()
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

        // The same resolver as the webhook: due summed across every invoice of the payment.
        return static function (array $snapshot): array {
            if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                return array();
            }

            $host = new \stdClass();
            \Loader::loadModels($host, array('Invoices'));
            if (!isset($host->Invoices) || !method_exists($host->Invoices, 'get')) {
                return array();
            }
            $invoices = $host->Invoices;

            return BillingState::fromInvoices($snapshot, static function (int $id) use ($invoices) {
                return $invoices->get($id);
            });
        };
    }

    private function resolvePaymentApplier(): callable
    {
        if ($this->paymentApplier !== null) {
            return $this->paymentApplier;
        }

        // Throws when Blesta did not store the transaction: the caller then keeps the snapshot
        // non-final and the next run retries.
        return static function (array $transaction, array $snapshot): void {
            if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                throw new \RuntimeException('Blesta Transactions model is unavailable.');
            }

            $host = new \stdClass();
            \Loader::loadModels($host, array('Transactions', 'GatewayManager'));
            if (!isset($host->Transactions) || !method_exists($host->Transactions, 'add')) {
                throw new \RuntimeException('Blesta Transactions model is unavailable.');
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

            // Blesta keys gateway payments by transaction_id + gateway (GatewayPayments::processNotification):
            // a payment the webhook already handed to Blesta is not added again.
            if (method_exists($host->Transactions, 'getByTransactionId')
                && $host->Transactions->getByTransactionId($transaction['transaction_id'], null, $gatewayId)) {
                return;
            }

            $txId = $host->Transactions->add($txVars);
            if (!$txId) {
                $errors = method_exists($host->Transactions, 'errors') ? $host->Transactions->errors() : null;
                throw new \RuntimeException(
                    'Blesta did not record the Paymos transaction.'
                    . (!empty($errors) ? ' ' . (string) json_encode($errors) : '')
                );
            }

            if (method_exists($host->Transactions, 'apply') && !empty($transaction['invoices'])) {
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

    /**
     * Register (or repair) the plugin cron task through the Blesta CronTasks API:
     * a global task definition (CronTasks::add) plus this company's 10-minute run (addTaskRun).
     */
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
            $cronTasks = $host->CronTasks;

            $task = $cronTasks->getByKey(self::CRON_KEY, self::PLUGIN_DIR, 'plugin');
            if (is_object($task) && isset($task->id)) {
                $taskId = (int) $task->id;
                // Earlier releases stored the language keys as a plain name (is_lang = 0).
                if ((string) ($task->is_lang ?? '') !== '1' && method_exists($cronTasks, 'edit')) {
                    $cronTasks->edit($taskId, array(
                        'name' => 'PaymosPlugin.cron.reconcile_name',
                        'description' => 'PaymosPlugin.cron.reconcile_desc',
                        'is_lang' => 1,
                    ));
                }
            } else {
                $taskId = (int) $cronTasks->add(array(
                    'key' => self::CRON_KEY,
                    'task_type' => 'plugin',
                    'dir' => self::PLUGIN_DIR,
                    'name' => 'PaymosPlugin.cron.reconcile_name',
                    'description' => 'PaymosPlugin.cron.reconcile_desc',
                    'is_lang' => 1,
                    'type' => 'interval',
                ));
                if ($taskId <= 0) {
                    $this->log('plugin.cron_register_error', array('error' => self::modelErrors($cronTasks)), 'warning');
                    return;
                }
            }

            $run = method_exists($cronTasks, 'getTaskRunByKey')
                ? $cronTasks->getTaskRunByKey(self::CRON_KEY, self::PLUGIN_DIR, false, 'plugin')
                : false;
            if (!$run && method_exists($cronTasks, 'addTaskRun')) {
                $runId = $cronTasks->addTaskRun($taskId, array(
                    'enabled' => 1,
                    'interval' => self::CRON_INTERVAL_MINUTES,
                ));
                if (!$runId) {
                    $this->log('plugin.cron_register_error', array('error' => self::modelErrors($cronTasks)), 'warning');
                }
            }
        } catch (\Throwable $e) {
            $this->log('plugin.cron_register_error', array('error' => $e->getMessage()), 'warning');
        }
    }

    /**
     * Delete this company's task run; the global task definition only with the last instance.
     */
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
            $cronTasks = $host->CronTasks;

            if (method_exists($cronTasks, 'getTaskRunByKey') && method_exists($cronTasks, 'deleteTaskRun')) {
                $run = $cronTasks->getTaskRunByKey(self::CRON_KEY, self::PLUGIN_DIR, false, 'plugin');
                if (is_object($run) && isset($run->task_run_id)) {
                    $cronTasks->deleteTaskRun((int) $run->task_run_id);
                }
            }

            if ($lastInstance && method_exists($cronTasks, 'deleteTask')) {
                $task = $cronTasks->getByKey(self::CRON_KEY, self::PLUGIN_DIR, 'plugin');
                if (is_object($task) && isset($task->id)) {
                    $cronTasks->deleteTask((int) $task->id, 'plugin', self::PLUGIN_DIR);
                }
            }
        } catch (\Throwable $e) {
            $this->log('plugin.cron_unregister_error', array('error' => $e->getMessage()), 'warning');
        }
    }

    /**
     * Releases before the staff permissions let every staff member open Connect and Refunds:
     * grant them to every staff group once so nobody loses access (see StaffPermissions).
     */
    private function grantExistingStaffGroups(int $pluginId): void
    {
        try {
            $settings = new CredentialStore($this->resolveDbDriver(), $this->keyMaterialProvider, $this->clock);
            if (!StaffPermissions::grantToAllStaffGroups($settings, $pluginId)) {
                $this->log('plugin.permissions_grant_pending', array('plugin_id' => $pluginId), 'info');
            }
        } catch (\Throwable $e) {
            $this->log('plugin.permissions_grant_error', array('error' => $e->getMessage()), 'warning');
        }
    }

    private function rememberPermissionsGranted(): void
    {
        try {
            StaffPermissions::markGranted(new CredentialStore($this->resolveDbDriver(), $this->keyMaterialProvider, $this->clock));
        } catch (\Throwable $e) {
            $this->log('plugin.permissions_grant_error', array('error' => $e->getMessage()), 'warning');
        }
    }

    /**
     * Release 1.0.0 registered injectAdminHelpers on Appcontroller.structure; drop that row
     * (Blesta also re-syncs events from getEvents() after a successful upgrade).
     */
    private function removeLegacyEvent(int $pluginId): void
    {
        if ($pluginId <= 0 || !class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
            return;
        }

        try {
            $host = new \stdClass();
            \Loader::loadModels($host, array('PluginManager'));
            if (isset($host->PluginManager) && method_exists($host->PluginManager, 'deleteEvent')) {
                $host->PluginManager->deleteEvent($pluginId, 'Appcontroller.structure');
            }
        } catch (\Throwable $e) {
            $this->log('plugin.event_cleanup_error', array('error' => $e->getMessage()), 'warning');
        }
    }

    /**
     * @param object $model
     */
    private static function modelErrors($model): string
    {
        $errors = method_exists($model, 'errors') ? $model->errors() : null;
        return !empty($errors) ? (string) json_encode($errors) : 'unknown error';
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
