<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Autoloader.php';
\PaymosBlesta\Autoloader::register();

use Paymos\Client;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\AmountGuard;
use PaymosBlesta\CallbackProcessor;
use PaymosBlesta\CheckoutService;
use PaymosBlesta\Config;
use PaymosBlesta\ConnectHandler;
use PaymosBlesta\CredentialStore;
use PaymosBlesta\DbDriverInterface;
use PaymosBlesta\PdoDbDriver;
use PaymosBlesta\PdoEventStore;
use PaymosBlesta\RefundAdapter;
use PaymosBlesta\RefundIntentStore;
use PaymosBlesta\SnapshotStore;
use PaymosBlesta\Translation;

/**
 * Paymos Non-Merchant Payment Gateway for Blesta (v5/v6, PHP 8.1+).
 *
 * Uses One-Click Connect Paymos (AesGcmEnvelope over Blesta.system_key),
 * crash-safe checkout with products[] and InvoiceReplacement, MultiEnvironmentWebhookVerifier,
 * and async-safe full & overpayment refunds via RefundCoordinator.
 */
class Paymos extends NonmerchantGateway
{
    /** @var array<string, mixed> */
    private $meta = array();

    /** @var string */
    private $currency = 'USD';

    /** @var DbDriverInterface|null */
    private $dbDriver;

    /** @var TransportInterface|null */
    private $transport;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $keyMaterialProvider;

    /** @var callable|null */
    private $rawBodyProvider;

    /** @var callable|null */
    private $headersProvider;

    /** @var callable|null */
    private $billingStateResolver;

    /** @var callable|null */
    private $lineItemsResolver;

    /** @var callable|null */
    private $refundNativeHandler;

    /** @var callable|null */
    private $refundAuthorizer;

    /** @var int */
    private $lastHttpStatus = 200;

    /** @var array<int, array{event: string, level: string, context: array<string, mixed>}> */
    private $logs = array();

    /** @var array<string, mixed>|null */
    private $lastWebhookResult;

    public function __construct()
    {
        $this->loadConfig(__DIR__ . DIRECTORY_SEPARATOR . 'config.json');
        Loader::loadComponents($this, array('Input'));
        Language::loadLang('paymos', null, __DIR__ . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR);
    }

    public function setDbDriver(DbDriverInterface $driver): void
    {
        $this->dbDriver = $driver;
    }

    public function setTransport(TransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
    }

    public function setKeyMaterialProvider(callable $provider): void
    {
        $this->keyMaterialProvider = $provider;
    }

    public function setWebhookInputProvider(callable $rawBodyProvider, callable $headersProvider): void
    {
        $this->rawBodyProvider = $rawBodyProvider;
        $this->headersProvider = $headersProvider;
    }

    public function setBillingStateResolver(callable $resolver): void
    {
        $this->billingStateResolver = $resolver;
    }

    public function setLineItemsResolver(callable $resolver): void
    {
        $this->lineItemsResolver = $resolver;
    }

    public function setRefundNativeHandler(callable $handler): void
    {
        $this->refundNativeHandler = $handler;
    }

    public function setRefundAuthorizer(callable $authorizer): void
    {
        $this->refundAuthorizer = $authorizer;
    }

    public function getLastHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    /**
     * @return array<int, array{event: string, level: string, context: array<string, mixed>}>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastWebhookResult(): ?array
    {
        return $this->lastWebhookResult;
    }

    /**
     * @param string $currency
     */
    public function setCurrency($currency): void
    {
        $this->currency = strtoupper(trim((string) $currency));
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    public function setMeta(?array $meta = null): void
    {
        $this->meta = is_array($meta) ? Config::sanitizeMetaForStorage($meta) : array();
    }

    /**
     * Render Connect Paymos settings UI (strictly zero manual secret inputs).
     *
     * @param array<string, mixed>|null $meta
     */
    public function getSettings(?array $meta = null): string
    {
        $rawMeta = is_array($meta) ? $meta : $this->meta;
        $safeMeta = Config::sanitizeMetaForStorage($rawMeta);

        $isConnected = false;
        try {
            $store = $this->createCredentialStore();
            $isConnected = $store->hasCredentials();
        } catch (\Throwable $e) {
            $isConnected = false;
        }

        $this->view = $this->makeView('settings', 'default', $this->relativeViewPath());
        Loader::loadHelpers($this->view, array('Form', 'Html'));

        $this->view->set('meta', $safeMeta);
        $this->view->set('is_connected', $isConnected);
        $this->view->set('modes', array(
            Config::ENV_SANDBOX => Language::_('Paymos.mode.sandbox', true),
            Config::ENV_LIVE => Language::_('Paymos.mode.live', true),
        ));
        $this->view->set('webhook_url', $this->buildWebhookUrl());
        $this->view->set('connect_url', $this->buildConnectUrl());
        $this->view->set('csrf_token', $this->resolveCsrfToken());
        $this->view->set('connect_labels', array(
            'button' => Language::_('Paymos.connect.button', true),
            'reconnect' => Language::_('Paymos.connect.reconnect', true),
            'starting' => Language::_('Paymos.connect.starting', true),
            'waiting' => Language::_('Paymos.connect.waiting', true),
            'open' => Language::_('Paymos.connect.open', true),
            'blocked' => Language::_('Paymos.connect.blocked', true),
            'code' => Language::_('Paymos.connect.code', true),
            'failed' => Language::_('Paymos.connect.failed', true),
        ));

        return (string) $this->view->fetch();
    }

    /**
     * Validate and save Connect-bound gateway settings (mode + button_text only).
     *
     * @param array<string, mixed> $meta
     * @return array{mode: string, button_text: string}
     */
    public function editSettings(array $meta): array
    {
        $sanitized = Config::sanitizeMetaForStorage($meta);
        $validationErrors = Config::validateMeta($sanitized);

        if (count($validationErrors) > 0) {
            $inputErrors = array();
            foreach ($validationErrors as $field => $detail) {
                $translated = Language::_($detail['code'], true);
                $message = (is_string($translated) && $translated !== '' && $translated !== $detail['code'])
                    ? $translated
                    : $detail['message'];
                $inputErrors[$field] = array(
                    'valid' => Config::sanitizeStatic($message),
                );
            }
            $this->Input->setErrors($inputErrors);
        } else {
            $this->Input->setErrors(array());
            $this->ensureStorageSchemasSafe();
        }

        return $sanitized;
    }

    /**
     * Credentials live in CredentialStore (AesGcmEnvelope), never in Blesta gateway meta.
     *
     * @return array<int, string>
     */
    public function encryptableFields(): array
    {
        return array();
    }

    /**
     * Build Paymos hosted checkout link via CheckoutService.
     *
     * @param array<string, mixed> $contact_info
     * @param float|int|string $amount
     * @param array<int, array<string, mixed>>|null $invoice_amounts
     * @param array<string, mixed>|null $options
     */
    public function buildProcess(
        array $contact_info,
        $amount,
        ?array $invoice_amounts = null,
        ?array $options = null
    ): string {
        $locale = Translation::detectLocale();
        $config = $this->resolveConfig();
        if ($config === null) {
            return '';
        }

        try {
            $checkoutService = $this->createCheckoutService($config);
            $clientId = isset($contact_info['client_id']) && is_scalar($contact_info['client_id'])
                ? trim((string) $contact_info['client_id'])
                : '';

            $checkout = $checkoutService->createCheckout(
                $clientId,
                $amount,
                $this->currency !== '' ? $this->currency : 'USD',
                is_array($invoice_amounts) ? $invoice_amounts : array(),
                is_array($options) ? $options : array()
            );
        } catch (\Throwable $e) {
            $safeMessage = isset($checkoutService)
                ? $checkoutService->formatSafeError($e, $locale)
                : $config->sanitize($e->getMessage());
            $this->recordLog('checkout.error', array('error' => $safeMessage), 'error', $config);
            $this->Input->setErrors(array(
                'checkout' => array('failed' => $safeMessage),
            ));
            return '';
        }

        $buttonText = $config->buttonText();
        if ($buttonText === '' || $buttonText === Config::DEFAULT_BUTTON_TEXT) {
            $buttonText = Translation::text('Paymos.buildprocess.submit', $locale);
        }

        $this->view = $this->makeView('process', 'default', $this->relativeViewPath());
        Loader::loadHelpers($this->view, array('Form', 'Html'));

        $this->view->set('payment_url', $checkout['payment_url']);
        $this->view->set('paymos_invoice_id', $checkout['paymos_invoice_id']);
        $this->view->set('external_order_id', $checkout['external_order_id']);
        $this->view->set('amount', $checkout['amount']);
        $this->view->set('currency', $checkout['currency']);
        $this->view->set('button_text', $buttonText);

        return (string) $this->view->fetch();
    }

    /**
     * Validate and process signed Paymos webhook callbacks (Sandbox + Live).
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    public function validate(array $get, array $post): ?array
    {
        $config = $this->resolveConfig();
        if ($config === null) {
            $this->emitHttpStatus(500);
            $this->lastWebhookResult = array(
                'http_status' => 500,
                'status' => 'configuration_error',
                'body' => 'Configuration error',
                'duplicate' => false,
                'transaction' => null,
                'environment' => null,
                'message' => 'Paymos is not connected.',
            );
            return null;
        }

        $rawBody = $this->readRawBody();
        $headers = $this->readHeaders();

        try {
            $processor = $this->createCallbackProcessor($config);
            $result = $processor->handle($rawBody, $headers);
        } catch (\Throwable $e) {
            $safeMessage = $config->sanitize($e->getMessage());
            $this->recordLog('webhook.exception', array('error' => $safeMessage), 'error', $config);
            $this->emitHttpStatus(500);
            $this->Input->setErrors(array(
                'webhook' => array('invalid' => $safeMessage),
            ));
            $this->lastWebhookResult = array(
                'http_status' => 500,
                'status' => 'error',
                'body' => 'Processing failed',
                'duplicate' => false,
                'transaction' => null,
                'environment' => null,
                'message' => $safeMessage,
            );
            return null;
        }

        $this->lastWebhookResult = $result;
        $httpStatus = isset($result['http_status']) ? (int) $result['http_status'] : 500;
        $this->emitHttpStatus($httpStatus);

        if ($httpStatus >= 400) {
            $this->Input->setErrors(array(
                'webhook' => array(
                    'invalid' => $config->sanitize((string) ($result['message'] ?? Language::_('Paymos.!error.webhook.invalid', true))),
                ),
            ));
        }

        return isset($result['transaction']) && is_array($result['transaction'])
            ? $result['transaction']
            : null;
    }

    /**
     * Handle browser return from Paymos hosted checkout (never trusts URL params to mark paid).
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function success(array $get, array $post): array
    {
        $externalOrderId = isset($get['external_order_id']) && is_scalar($get['external_order_id'])
            ? trim((string) $get['external_order_id'])
            : '';
        $invoiceId = isset($get['invoice_id']) && is_scalar($get['invoice_id'])
            ? trim((string) $get['invoice_id'])
            : '';

        $snapshot = null;
        try {
            $store = new SnapshotStore($this->resolveDbDriver(), $this->clock);
            if ($externalOrderId !== '') {
                $snapshot = $store->findByExternalOrderId($externalOrderId);
            } elseif ($invoiceId !== '') {
                $snapshot = $store->findByInvoiceId($invoiceId);
            }
        } catch (\Throwable $e) {
            $snapshot = null;
        }

        if (is_array($snapshot)) {
            $meta = SnapshotStore::decodeMeta(isset($snapshot['payload_meta']) ? (string) $snapshot['payload_meta'] : null);
            $finalStatus = isset($snapshot['final_status']) && is_string($snapshot['final_status'])
                ? strtolower(trim($snapshot['final_status']))
                : '';
            $isPaid = in_array($finalStatus, array('paid', 'paid_over'), true);

            return array(
                'client_id' => $snapshot['client_id'],
                'amount' => (string) $snapshot['amount'],
                'currency' => (string) $snapshot['currency'],
                'invoices' => isset($meta['invoices']) && is_array($meta['invoices']) ? $meta['invoices'] : array(),
                'status' => $isPaid ? 'approved' : 'pending',
                'reference_id' => (string) $snapshot['paymos_invoice_id'],
                'transaction_id' => isset($snapshot['transaction_id']) && $snapshot['transaction_id'] !== null
                    ? (string) $snapshot['transaction_id']
                    : null,
                'parent_transaction_id' => null,
            );
        }

        return array(
            'client_id' => isset($get['client_id']) && is_scalar($get['client_id']) ? (string) $get['client_id'] : null,
            'amount' => null,
            'currency' => $this->currency,
            'invoices' => array(),
            'status' => 'pending',
            'reference_id' => null,
            'transaction_id' => null,
            'parent_transaction_id' => null,
        );
    }

    /**
     * Async-safe native refund hook.
     * Returns ['status' => 'refunded', ...] ONLY once Paymos confirms completed + applied.
     * While pending/processing/unknown, returns null + informational Input error (zero premature debit).
     *
     * @param string|null $reference_id Paymos invoice_id
     * @param string|null $transaction_id Confirmed tx_hash or fallback invoice_id
     * @param float|int|string $amount
     * @param string|null $notes
     * @return array<string, mixed>|null
     */
    public function refund($reference_id, $transaction_id, $amount, $notes = null): ?array
    {
        $config = $this->resolveConfig();
        if ($config === null) {
            return null;
        }

        try {
            $adapter = $this->createRefundAdapter($config);
            $result = $adapter->nativeRefund(array(
                'reference_id' => $reference_id !== null ? (string) $reference_id : '',
                'transaction_id' => $transaction_id !== null ? (string) $transaction_id : '',
                'amount' => $amount,
                'currency' => $this->currency,
                'notes' => $notes !== null ? (string) $notes : null,
            ));

            if ($result !== null) {
                return $result;
            }

            $message = $adapter->lastNativeMessage();
            if ($message === '') {
                $message = Translation::text('Paymos.refunds.native_in_progress');
            }
            $this->Input->setErrors(array(
                'refund' => array('status' => $config->sanitize($message)),
            ));
            return null;
        } catch (\Throwable $e) {
            $safeMessage = $config->sanitize($e->getMessage());
            $this->recordLog('refund.error', array('error' => $safeMessage), 'error', $config);
            $this->Input->setErrors(array(
                'refund' => array('failed' => $safeMessage),
            ));
            return null;
        }
    }

    private function resolveConfig(): ?Config
    {
        try {
            return Config::fromStore($this->meta, $this->createCredentialStore());
        } catch (\Throwable $e) {
            $safeMessage = Config::sanitizeStatic($e->getMessage());
            $this->recordLog('config.invalid', array('error' => $safeMessage), 'error', null);
            $this->Input->setErrors(array(
                'config' => array('invalid' => $safeMessage),
            ));
            return null;
        }
    }

    private function createCredentialStore(): CredentialStore
    {
        return new CredentialStore(
            $this->resolveDbDriver(),
            $this->keyMaterialProvider,
            $this->clock
        );
    }

    private function createCheckoutService(Config $config): CheckoutService
    {
        $snapshots = new SnapshotStore($this->resolveDbDriver(), $this->clock);
        return new CheckoutService(
            $config,
            $snapshots,
            $this->buildClientFactory(),
            $this->resolveLineItemsCallback(),
            $this->clock,
            $this->buildLogger($config)
        );
    }

    private function createCallbackProcessor(Config $config): CallbackProcessor
    {
        $driver = $this->resolveDbDriver();
        $snapshots = new SnapshotStore($driver, $this->clock);
        $eventStore = new PdoEventStore($driver, $this->clock);
        $refundAdapter = $this->createRefundAdapter($config);

        return new CallbackProcessor(
            $config,
            $snapshots,
            $eventStore,
            $this->buildClientFactory(),
            $this->resolveBillingStateCallback(),
            static function ($event, string $environment) use ($refundAdapter): void {
                $refundAdapter->callback($event, $environment);
            },
            null,
            $this->clock,
            $this->buildLogger($config)
        );
    }

    private function createRefundAdapter(Config $config): RefundAdapter
    {
        $driver = $this->resolveDbDriver();
        $credentialStore = $this->createCredentialStore();
        $snapshots = new SnapshotStore($driver, $this->clock);
        $intentStore = new RefundIntentStore($driver, $credentialStore, $this->clock);

        return new RefundAdapter(
            $config,
            $credentialStore,
            $snapshots,
            $intentStore,
            $this->buildClientFactory(),
            $this->refundNativeHandler,
            $this->refundAuthorizer,
            $this->clock,
            $this->buildLogger($config)
        );
    }

    private function buildClientFactory(): callable
    {
        $transport = $this->transport;
        return static function (Config $cfg, string $environment) use ($transport): Client {
            return new Client($cfg->clientConfig($environment), $transport);
        };
    }

    private function buildLogger(?Config $config): callable
    {
        $self = $this;
        return static function (string $event, array $context, string $level) use ($self, $config): void {
            $self->recordLog($event, $context, $level, $config);
        };
    }

    private function resolveDbDriver(): DbDriverInterface
    {
        if ($this->dbDriver !== null) {
            return $this->dbDriver;
        }

        Loader::loadComponents($this, array('Record'));
        if (!isset($this->Record) || !is_object($this->Record) || !method_exists($this->Record, 'connect')) {
            throw new \RuntimeException('Blesta Record component is unavailable.');
        }

        $pdo = $this->Record->connect();
        if (!$pdo instanceof \PDO) {
            throw new \RuntimeException('Blesta Record::connect() did not return a PDO instance.');
        }

        $this->dbDriver = new PdoDbDriver($pdo);
        return $this->dbDriver;
    }

    private function resolveLineItemsCallback(): callable
    {
        if ($this->lineItemsResolver !== null) {
            return $this->lineItemsResolver;
        }

        return function (array $invoices, string $currency): array {
            if (count($invoices) !== 1 || !isset($invoices[0]['id'])) {
                return array();
            }
            if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
                return array();
            }

            try {
                Loader::loadModels($this, array('Invoices'));
                if (!isset($this->Invoices) || !is_object($this->Invoices) || !method_exists($this->Invoices, 'get')) {
                    return array();
                }
                $invoice = $this->Invoices->get((int) $invoices[0]['id']);
                if (!is_object($invoice) || !isset($invoice->line_items) || !is_array($invoice->line_items)) {
                    return array();
                }
                if (isset($invoice->currency) && is_string($invoice->currency)
                    && strtoupper(trim($invoice->currency)) !== strtoupper(trim($currency))) {
                    return array();
                }
                return $invoice->line_items;
            } catch (\Throwable $e) {
                return array();
            }
        };
    }

    private function resolveBillingStateCallback(): ?callable
    {
        if ($this->billingStateResolver !== null) {
            return $this->billingStateResolver;
        }

        if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
            return null;
        }

        return function (array $snapshot): array {
            $meta = SnapshotStore::decodeMeta(isset($snapshot['payload_meta']) ? (string) $snapshot['payload_meta'] : null);
            $invoices = isset($meta['invoices']) && is_array($meta['invoices']) ? $meta['invoices'] : array();
            if (count($invoices) === 0) {
                return array();
            }

            try {
                Loader::loadModels($this, array('Invoices'));
                if (!isset($this->Invoices) || !is_object($this->Invoices) || !method_exists($this->Invoices, 'get')) {
                    return array();
                }

                $allPaid = true;
                $currency = '';
                $currencyMismatch = false;
                $dueAmount = '0';
                $loadedCount = 0;

                foreach ($invoices as $item) {
                    if (!isset($item['id'])) {
                        continue;
                    }
                    $invoice = $this->Invoices->get((int) $item['id']);
                    if (!is_object($invoice)) {
                        $allPaid = false;
                        continue;
                    }
                    $loadedCount++;
                    $due = isset($invoice->due) ? AmountGuard::normalizeDecimal($invoice->due) : null;
                    $isClosed = !empty($invoice->date_closed) || ($due !== null && ($due === '0' || $due[0] === '-'));
                    if (!$isClosed) {
                        $allPaid = false;
                    }
                    if (isset($invoice->currency) && is_string($invoice->currency)) {
                        $invCurr = strtoupper(trim($invoice->currency));
                        if ($invCurr !== '') {
                            if ($currency === '') {
                                $currency = $invCurr;
                            } elseif ($currency !== $invCurr) {
                                $currencyMismatch = true;
                            }
                        }
                    }

                    $rawBalance = isset($invoice->due) ? $invoice->due : (isset($invoice->total) ? $invoice->total : null);
                    if ($rawBalance !== null) {
                        $norm = AmountGuard::normalizeDecimal($rawBalance);
                        if ($norm !== null) {
                            $dueAmount = self::addDecimals($dueAmount, $norm);
                        }
                    }
                }

                if ($loadedCount === 0) {
                    return array();
                }

                if ($currencyMismatch) {
                    return array(
                        'paid' => false,
                        'amount' => $dueAmount,
                        'currency' => '__CURRENCY_MISMATCH__',
                    );
                }

                $normDue = AmountGuard::normalizeDecimal($dueAmount);
                return array(
                    'paid' => $allPaid,
                    'amount' => $normDue !== null ? $normDue : (string) $snapshot['amount'],
                    'currency' => $currency !== '' ? $currency : (string) $snapshot['currency'],
                );
            } catch (\Throwable $e) {
                return array();
            }
        };
    }

    /**
     * Exact arbitrary-precision decimal addition (no IEEE-754 float rounding).
     */
    public static function addDecimals(string $left, string $right): string
    {
        $a = AmountGuard::normalizeDecimal($left);
        $b = AmountGuard::normalizeDecimal($right);
        if ($a === null || $b === null) {
            throw new \InvalidArgumentException('Invalid decimal operand for exact addition.');
        }

        $aNeg = $a[0] === '-';
        $bNeg = $b[0] === '-';
        $aAbs = $aNeg ? substr($a, 1) : $a;
        $bAbs = $bNeg ? substr($b, 1) : $b;

        $aParts = explode('.', $aAbs, 2);
        $bParts = explode('.', $bAbs, 2);
        $aFrac = $aParts[1] ?? '';
        $bFrac = $bParts[1] ?? '';
        $scale = max(strlen($aFrac), strlen($bFrac));

        $aInt = ltrim($aParts[0] . str_pad($aFrac, $scale, '0'), '0');
        $bInt = ltrim($bParts[0] . str_pad($bFrac, $scale, '0'), '0');
        $aInt = $aInt === '' ? '0' : $aInt;
        $bInt = $bInt === '' ? '0' : $bInt;

        if ($aNeg === $bNeg) {
            $digits = self::addDigitStrings($aInt, $bInt);
            $neg = $aNeg;
        } else {
            $cmp = self::compareDigitStrings($aInt, $bInt);
            if ($cmp === 0) {
                return '0';
            }
            if ($cmp > 0) {
                $digits = self::subtractDigitStrings($aInt, $bInt);
                $neg = $aNeg;
            } else {
                $digits = self::subtractDigitStrings($bInt, $aInt);
                $neg = $bNeg;
            }
        }

        if ($scale > 0) {
            if (strlen($digits) <= $scale) {
                $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            }
            $splitPos = strlen($digits) - $scale;
            $formatted = substr($digits, 0, $splitPos) . '.' . substr($digits, $splitPos);
        } else {
            $formatted = $digits;
        }

        $result = AmountGuard::normalizeDecimal(($neg ? '-' : '') . $formatted);
        return $result !== null ? $result : '0';
    }

    private static function addDigitStrings(string $a, string $b): string
    {
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $carry = 0;
        $out = '';

        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += ord($a[$i]) - 48;
                $i--;
            }
            if ($j >= 0) {
                $sum += ord($b[$j]) - 48;
                $j--;
            }
            $out = (string) ($sum % 10) . $out;
            $carry = (int) floor($sum / 10);
        }

        $trimmed = ltrim($out, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function compareDigitStrings(string $a, string $b): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA !== $lenB) {
            return $lenA < $lenB ? -1 : 1;
        }
        return strcmp($a, $b);
    }

    private static function subtractDigitStrings(string $larger, string $smaller): string
    {
        $i = strlen($larger) - 1;
        $j = strlen($smaller) - 1;
        $borrow = 0;
        $out = '';

        while ($i >= 0) {
            $digit = (ord($larger[$i]) - 48) - $borrow;
            if ($j >= 0) {
                $digit -= (ord($smaller[$j]) - 48);
                $j--;
            }
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out = (string) $digit . $out;
            $i--;
        }

        $trimmed = ltrim($out, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }

    private function ensureStorageSchemasSafe(): void
    {
        try {
            $driver = $this->resolveDbDriver();
            $credStore = new CredentialStore($driver, $this->keyMaterialProvider, $this->clock);
            $credStore->ensureSchema();
            (new PdoEventStore($driver, $this->clock))->ensureSchema();
            (new SnapshotStore($driver, $this->clock))->ensureSchema();
            (new RefundIntentStore($driver, $credStore, $this->clock))->ensureSchema();
        } catch (\Throwable $e) {
            // Defer schema initialization when DB component is unavailable in isolated contexts.
        }
    }

    private function buildWebhookUrl(): string
    {
        $base = $this->resolveInstallationBaseUrl();
        if ($base === '') {
            return ConnectHandler::CALLBACK_PATH;
        }

        return $base . ConnectHandler::CALLBACK_PATH;
    }

    private function buildConnectUrl(): string
    {
        $adminUri = '/admin/';
        if (class_exists('Configure') && method_exists('Configure', 'get')) {
            $routeAdmin = \Configure::get('Route.admin');
            if (is_scalar($routeAdmin) && trim((string) $routeAdmin) !== '') {
                $adminUri = '/' . trim((string) $routeAdmin, '/') . '/';
            }
        }

        return $adminUri . 'plugin/paymos/admin_connect/';
    }

    private function resolveInstallationBaseUrl(): string
    {
        if (class_exists('Configure') && method_exists('Configure', 'get')) {
            $rawBase = \Configure::get('Blesta.gw_callback_url');
            if (is_scalar($rawBase)) {
                $trimmed = rtrim(trim((string) $rawBase), '/');
                if ($trimmed !== '') {
                    $origin = preg_replace('#/callback/gw(?:/.*)?\z#i', '', $trimmed);
                    if (is_string($origin) && $origin !== '') {
                        return rtrim($origin, '/');
                    }
                }
            }
        }

        return '';
    }

    private function resolveCsrfToken(): string
    {
        if (isset($this->view) && is_object($this->view) && isset($this->view->Form)
            && is_object($this->view->Form) && method_exists($this->view->Form, 'getCsrfToken')) {
            try {
                $token = $this->view->Form->getCsrfToken();
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
                // Fall through to session token fallback.
            }
        }

        if (isset($_SESSION['_csrf_token']) && is_scalar($_SESSION['_csrf_token'])) {
            return trim((string) $_SESSION['_csrf_token']);
        }

        return '';
    }

    private function relativeViewPath(): string
    {
        if (defined('ROOTWEBDIR') && is_string(ROOTWEBDIR) && ROOTWEBDIR !== '') {
            return str_replace(ROOTWEBDIR, '', __DIR__) . DIRECTORY_SEPARATOR;
        }

        return __DIR__ . DIRECTORY_SEPARATOR;
    }

    private function readRawBody(): string
    {
        if ($this->rawBodyProvider !== null) {
            return (string) call_user_func($this->rawBodyProvider);
        }

        $body = file_get_contents('php://input');
        return is_string($body) ? $body : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function readHeaders(): array
    {
        if ($this->headersProvider !== null) {
            $headers = call_user_func($this->headersProvider);
            return is_array($headers) ? $headers : array();
        }

        $headers = array();
        if (function_exists('getallheaders')) {
            $raw = getallheaders();
            if (is_array($raw)) {
                $headers = $raw;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (strncmp((string) $key, 'HTTP_', 5) === 0 && is_scalar($value)) {
                $headers[$key] = (string) $value;
            }
        }

        return $headers;
    }

    private function emitHttpStatus(int $statusCode): void
    {
        $this->lastHttpStatus = $statusCode;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($statusCode);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordLog(string $event, array $context, string $level, ?Config $config): void
    {
        $sanitizedContext = array();
        foreach ($context as $key => $value) {
            $raw = is_scalar($value) ? (string) $value : (string) json_encode($value);
            $sanitizedContext[(string) $key] = $config !== null
                ? $config->sanitize($raw)
                : Config::sanitizeStatic($raw);
        }

        $this->logs[] = array(
            'event' => $event,
            'level' => $level,
            'context' => $sanitizedContext,
        );

        if (method_exists($this, 'log')) {
            $payload = json_encode(array(
                'event' => $event,
                'level' => $level,
                'context' => $sanitizedContext,
            ), JSON_UNESCAPED_SLASHES);

            $direction = strncmp($event, 'webhook.', 8) === 0 ? 'input' : 'output';
            $success = $level !== 'error' && $level !== 'warning';
            $this->log($this->buildWebhookUrl(), is_string($payload) ? $payload : '{}', $direction, $success);
        }
    }
}
