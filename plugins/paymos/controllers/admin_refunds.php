<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'paymos_controller.php';

use Paymos\Client;
use PaymosBlesta\Config;
use PaymosBlesta\RefundAdapter;
use PaymosBlesta\RefundIntentStore;
use PaymosBlesta\SnapshotStore;
use PaymosBlesta\Translation;

/**
 * Staff-only CSRF-protected controller for Paymos Refunds (full + overpayment) panel & submit.
 */
class AdminRefunds extends PaymosController
{
    /** @var callable|null */
    private $clientFactory;

    /** @var callable|null */
    private $refundNativeHandler;

    public function setClientFactory(callable $factory): void
    {
        $this->clientFactory = $factory;
    }

    public function setRefundNativeHandler(callable $handler): void
    {
        $this->refundNativeHandler = $handler;
    }

    /**
     * panel() and submit() are fetch() endpoints of the refunds page; index() is the page.
     */
    protected function respondsWithJson(): bool
    {
        return isset($this->action) && in_array($this->action, array('panel', 'submit'), true);
    }

    public function index()
    {
        $invoiceId = $this->resolveInvoiceIdFromRequest();
        $response = $this->handleIndexPage($invoiceId);
        if (method_exists($this, 'set')) {
            if ($response['http_status'] !== 200 && PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code($response['http_status']);
            }
            $this->set('content', $response['html']);
            return null;
        }
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($response['http_status']);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store');
        }
        echo $response['html'];
        return false;
    }

    /**
     * Render a complete staff-authenticated HTML page for Billing -> Paymos Refunds
     * with invoice lookup, recent paid Paymos invoices, full + overpayment buttons, and JS submit handler.
     *
     * @return array{http_status: int, html: string, panel: ?array<string, mixed>, error: ?string}
     */
    public function handleIndexPage(int $invoiceId = 0, ?string $locale = null): array
    {
        $lang = Translation::normalizeLocale($locale ?? Translation::detectLocale());
        $e = static function (string $value): string {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };

        try {
            $this->requireStaffAuth();
        } catch (\Throwable $authError) {
            $denied = $this->adminErrorBody($authError->getMessage());
            return array(
                'http_status' => 403,
                'html' => '<div class="paymos-refunds-page"><p class="error">' . $e($denied['error']) . '</p></div>',
                'panel' => null,
                'error' => $authError->getMessage(),
            );
        }

        $adminPrefix = $this->resolveAdminRoutePrefix();
        $indexUrl = $adminPrefix . 'plugin/paymos/admin_refunds/';
        $submitUrl = $adminPrefix . 'plugin/paymos/admin_refunds/submit/';
        $csrfToken = $this->resolveCsrfToken();

        $panel = null;
        $error = null;
        if ($invoiceId > 0) {
            try {
                $adapter = $this->createRefundAdapter(false);
                $panel = $adapter->panel($invoiceId, $lang);
            } catch (\Throwable $eObj) {
                $error = Translation::text('Paymos.!error.refund.failed', $lang)
                    . ' (' . Config::sanitizeStatic($eObj->getMessage()) . ')';
            }
        }

        $recentPaid = array();
        try {
            $snapshots = new SnapshotStore($this->resolveDbDriver(), $this->clock);
            $recentPaid = $snapshots->findRecentPaid(25);
        } catch (\Throwable $listErr) {
            $recentPaid = array();
        }

        $html = '<div id="paymos-refunds-page" class="paymos-refunds-page"'
            . ' data-submit-url="' . $e($submitUrl) . '"'
            . ' data-csrf-token="' . $e($csrfToken) . '"'
            . ' data-text-status="' . $e(Translation::text('Paymos.refunds.submit_status', $lang)) . '"'
            . ' data-text-failed="' . $e(Translation::text('Paymos.!error.refund.failed', $lang)) . '"'
            . ' data-text-network="' . $e(Translation::text('Paymos.refunds.network_error', $lang)) . '">';
        $html .= '<h3>' . $e(Translation::text('Paymos.refunds.title', $lang)) . '</h3>';
        $html .= '<form method="get" action="' . $e($indexUrl) . '" class="paymos-refunds-lookup">';
        $html .= '<label for="paymos-invoice-id">' . $e(Translation::text('Paymos.refunds.lookup_label', $lang)) . '</label> ';
        $html .= '<input type="number" min="1" id="paymos-invoice-id" name="invoice_id" value="' . ($invoiceId > 0 ? $invoiceId : '') . '" required /> ';
        $html .= '<button type="submit" class="btn btn-primary">' . $e(Translation::text('Paymos.refunds.lookup_submit', $lang)) . '</button>';
        $html .= '</form>';

        if ($error !== null) {
            $html .= '<p class="paymos-refunds-error">' . $e($error) . '</p>';
        }

        if ($panel !== null && isset($panel['html']) && is_string($panel['html'])) {
            $html .= $panel['html'];
            $html .= '<div id="paymos-refund-feedback" role="status" aria-live="polite"></div>';
        } elseif (count($recentPaid) > 0) {
            $html .= '<ul class="paymos-refunds-recent">';
            foreach ($recentPaid as $snap) {
                $meta = SnapshotStore::decodeMeta(isset($snap['payload_meta']) ? (string) $snap['payload_meta'] : null);
                $blestaId = isset($snap['blesta_invoice_id']) && is_numeric($snap['blesta_invoice_id'])
                    ? (int) $snap['blesta_invoice_id']
                    : 0;
                if ($blestaId <= 0 && isset($meta['blesta_invoice_id']) && is_numeric($meta['blesta_invoice_id'])) {
                    $blestaId = (int) $meta['blesta_invoice_id'];
                }
                if ($blestaId <= 0 && isset($meta['invoices'][0]['id']) && is_numeric($meta['invoices'][0]['id'])) {
                    $blestaId = (int) $meta['invoices'][0]['id'];
                }
                if ($blestaId <= 0 && isset($snap['platform_ref']) && is_string($snap['platform_ref'])
                    && preg_match('/\Ainv_([1-9][0-9]*)\z/', $snap['platform_ref'], $m) === 1) {
                    $blestaId = (int) $m[1];
                }
                if ($blestaId <= 0) {
                    continue;
                }
                $statusLabel = (string) ($snap['final_status'] ?? $snap['status'] ?? 'paid');
                $html .= '<li><a href="' . $e($indexUrl . '?invoice_id=' . $blestaId) . '">'
                    . $e(strtr(Translation::text('Paymos.refunds.recent_invoice', $lang), array('{id}' => (string) $blestaId)))
                    . ' (' . $e((string) ($snap['amount'] ?? '')) . ' ' . $e((string) ($snap['currency'] ?? ''))
                    . ' — ' . $e($statusLabel) . ')</a></li>';
            }
            $html .= '</ul>';
        }

        $html .= '<script>(function(){'
            . 'var root=document.getElementById("paymos-refunds-page");if(!root){return;}'
            . 'var submitUrl=root.getAttribute("data-submit-url")||"";'
            . 'var csrf=root.getAttribute("data-csrf-token")||"";'
            . 'var tStatus=root.getAttribute("data-text-status")||"";'
            . 'var tFailed=root.getAttribute("data-text-failed")||"";'
            . 'var tNetwork=root.getAttribute("data-text-network")||"";'
            . 'root.addEventListener("click",function(ev){'
            . 'var btn=ev.target&&ev.target.closest?ev.target.closest(".paymos-refund-btn"):null;'
            . 'if(!btn||btn.disabled){return;}'
            . 'var panel=btn.closest(".paymos-refunds-panel");'
            . 'var invId=panel?panel.getAttribute("data-invoice-id"):"";'
            . 'var payId=panel?(panel.getAttribute("data-paymos-invoice-id")||""):"";'
            . 'var mode=btn.getAttribute("data-mode")||"";'
            . 'var token=btn.getAttribute("data-token")||"";'
            . 'var fb=document.getElementById("paymos-refund-feedback");'
            . 'if(!submitUrl||!invId||!mode||!token){return;}'
            . 'btn.disabled=true;'
            . 'var body=new URLSearchParams({invoice_id:invId,paymos_invoice_id:payId,mode:mode,request_token:token,_csrf_token:csrf});'
            . 'fetch(submitUrl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded","X-CSRF-Token":csrf},body:body.toString()})'
            . '.then(function(r){return r.json().then(function(d){return {ok:r.ok,data:d};});})'
            . '.then(function(res){if(fb){fb.textContent=res.ok?tStatus.replace("{status}",res.data.cash_state):(res.data&&res.data.error?res.data.error:tFailed);}if(res.ok){window.location.reload();}else{btn.disabled=false;}})'
            . '.catch(function(){if(fb){fb.textContent=tNetwork;}btn.disabled=false;});'
            . '});})();</script>';
        $html .= '</div>';

        return array(
            'http_status' => 200,
            'html' => $html,
            'panel' => $panel,
            'error' => $error,
        );
    }

    public function panel()
    {
        $invoiceId = $this->resolveInvoiceIdFromRequest();
        $response = $this->handlePanelRequest($invoiceId);
        $this->emitJsonResponse($response['http_status'], $response['body']);
        return false;
    }

    public function submit()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $response = $this->handleSubmitRequest($method, $_POST, $_SERVER);
        $this->emitJsonResponse($response['http_status'], $response['body']);
        return false;
    }

    private function resolveInvoiceIdFromRequest(): int
    {
        if (isset($_GET['invoice_id']) && is_numeric($_GET['invoice_id']) && (int) $_GET['invoice_id'] > 0) {
            return (int) $_GET['invoice_id'];
        }
        if (isset($this->get) && is_array($this->get)) {
            if (isset($this->get['invoice_id']) && is_numeric($this->get['invoice_id']) && (int) $this->get['invoice_id'] > 0) {
                return (int) $this->get['invoice_id'];
            }
            if (isset($this->get[0]) && is_numeric($this->get[0]) && (int) $this->get[0] > 0) {
                return (int) $this->get[0];
            }
        }
        return 0;
    }

    /**
     * Load Paymos Refunds panel state & HTML for a Blesta invoice (requires staff auth).
     *
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function handlePanelRequest(int $invoiceId, ?string $locale = null): array
    {
        try {
            $this->requireStaffAuth();
        } catch (\Throwable $authError) {
            return array(
                'http_status' => 403,
                'body' => $this->adminErrorBody($authError->getMessage()),
            );
        }

        $lang = Translation::normalizeLocale($locale ?? Translation::detectLocale());
        if ($invoiceId <= 0) {
            return array(
                'http_status' => 400,
                'body' => array(
                    'error' => Translation::text('Paymos.refunds.invoice_required', $lang),
                    'reason' => 'refund_payment_key_invalid',
                ),
            );
        }

        try {
            $adapter = $this->createRefundAdapter(false);
            $panel = $adapter->panel($invoiceId, $lang);
            return array('http_status' => 200, 'body' => $panel);
        } catch (\Throwable $error) {
            return array(
                'http_status' => 400,
                'body' => array(
                    'error' => Translation::text('Paymos.!error.refund.failed', $lang),
                    'reason' => Config::sanitizeStatic($error->getMessage()),
                ),
            );
        }
    }

    /**
     * Submit a full or overpayment refund (requires POST, staff auth, CSRF, UUIDv4 request_token).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $headers
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function handleSubmitRequest(string $method, array $post, array $headers = array()): array
    {
        if (strtoupper($method) !== 'POST') {
            return array(
                'http_status' => 405,
                'body' => $this->adminErrorBody('paymos_method_not_allowed'),
            );
        }

        try {
            $this->requireStaffAuth();
            $this->requireCsrfToken($post, $headers);
        } catch (\Throwable $authError) {
            return array(
                'http_status' => 403,
                'body' => $this->adminErrorBody($authError->getMessage()),
            );
        }

        $input = $post;
        unset($input['_csrf_token'], $input['action']);

        try {
            $adapter = $this->createRefundAdapter(true);
            $intent = $adapter->submit($input);

            return array(
                'http_status' => 200,
                'body' => array(
                    'operation_id' => $intent->operationId(),
                    'mode' => $intent->mode(),
                    'cash_state' => $intent->cashState(),
                    'projection_state' => $intent->projectionState(),
                    'withdrawal_id' => $intent->withdrawalId(),
                    'review_reason' => $intent->toArray()['review_reason'],
                ),
            );
        } catch (\Throwable $error) {
            $reason = Config::sanitizeStatic($error->getMessage());
            $locale = Translation::detectLocale();
            $message = Translation::text('Paymos.!error.refund.failed', $locale);
            if ($reason === 'refund_reconnect_required') {
                $message .= ' ' . Translation::text('Paymos.refunds.reconnect_hint', $locale);
            } elseif ($reason === 'refund_partial_unsupported') {
                $message = Translation::text('Paymos.!error.refund.partial_unsupported', $locale);
            } elseif ($reason === 'refund_payment_selection_required') {
                $message = Translation::text('Paymos.!error.refund.payment_selection_required', $locale);
            }

            $status = in_array($reason, array(
                'refund_managed_input_only',
                'refund_partial_unsupported',
                'refund_durable_token_required',
                'refund_mode_invalid',
                'refund_payment_key_invalid',
                'refund_cash_only_input',
                'refund_payment_selection_required',
            ), true) ? 400 : 409;

            return array(
                'http_status' => $status,
                'body' => array(
                    'error' => $message,
                    'reason' => $reason,
                ),
            );
        }
    }

    private function createRefundAdapter(bool $alreadyCsrfVerified): RefundAdapter
    {
        $driver = $this->resolveDbDriver();
        $credentialStore = $this->createCredentialStore();
        $config = Config::fromStore($this->loadGatewayMeta(), $credentialStore);
        $snapshots = new SnapshotStore($driver, $this->clock);
        $intentStore = new RefundIntentStore($driver, $credentialStore, $this->clock);

        $clientFactory = $this->clientFactory ?: static function (Config $cfg, string $environment): Client {
            return new Client($cfg->clientConfig($environment));
        };

        // Authorization and CSRF were already enforced by the controller above.
        $authorizer = static function (bool $requireCsrf) use ($alreadyCsrfVerified): void {
            if ($requireCsrf && !$alreadyCsrfVerified) {
                throw new \RuntimeException('refund_admin_access_denied');
            }
        };

        return new RefundAdapter(
            $config,
            $credentialStore,
            $snapshots,
            $intentStore,
            $clientFactory,
            $this->refundNativeHandler,
            $authorizer,
            $this->clock
        );
    }
}
