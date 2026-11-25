<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'paymos_controller.php';

use Paymos\Connect\DeviceConnectClient;
use PaymosBlesta\Config;
use PaymosBlesta\ConnectHandler;
use PaymosBlesta\Translation;

/**
 * Staff-only CSRF-protected AJAX controller for One-Click Connect Paymos (start & poll).
 */
class AdminConnect extends PaymosController
{
    /** @var DeviceConnectClient|null */
    private $connectClient;

    public function setConnectClient(DeviceConnectClient $client): void
    {
        $this->connectClient = $client;
    }

    public function index(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $response = $this->handleRequest($method, $_POST, $_SERVER);
        $this->emitJsonResponse($response['http_status'], $response['body']);
    }

    public function start(): void
    {
        $post = $_POST;
        $post['action'] = 'start';
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $response = $this->handleRequest($method, $post, $_SERVER);
        $this->emitJsonResponse($response['http_status'], $response['body']);
    }

    public function poll(): void
    {
        $post = $_POST;
        $post['action'] = 'poll';
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $response = $this->handleRequest($method, $post, $_SERVER);
        $this->emitJsonResponse($response['http_status'], $response['body']);
    }

    /**
     * Pure testable request handler for Connect Paymos AJAX actions.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $headers
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function handleRequest(
        string $method,
        array $post,
        array $headers = array(),
        ?string $sourceUrlOverride = null
    ): array {
        if (strtoupper($method) !== 'POST') {
            return array(
                'http_status' => 405,
                'body' => array('error' => 'POST request is required.'),
            );
        }

        try {
            $this->requireStaffAuth();
            $this->requireCsrfToken($post, $headers);
        } catch (\Throwable $authError) {
            return array(
                'http_status' => 403,
                'body' => array('error' => $authError->getMessage()),
            );
        }

        $action = isset($post['action']) && is_scalar($post['action'])
            ? strtolower(trim((string) $post['action']))
            : '';
        $sourceUrl = $this->resolveBlestaBaseUrl($sourceUrlOverride);

        try {
            $handler = new ConnectHandler(
                $this->createCredentialStore(),
                $this->connectClient
            );

            if ($action === 'start') {
                $returnUrl = isset($post['return_url']) && is_scalar($post['return_url'])
                    ? trim((string) $post['return_url'])
                    : '';
                $result = $handler->start($sourceUrl, $returnUrl);
                return array('http_status' => 200, 'body' => $result);
            }

            if ($action === 'poll') {
                $result = $handler->poll($sourceUrl);
                return array('http_status' => 200, 'body' => $result);
            }

            return array(
                'http_status' => 400,
                'body' => array('error' => 'Invalid Connect action.'),
            );
        } catch (\Throwable $error) {
            $locale = Translation::detectLocale();
            return array(
                'http_status' => 400,
                'body' => array(
                    'error' => Translation::text('Paymos.connect.failed', $locale),
                    'reason' => Config::sanitizeStatic($error->getMessage()),
                ),
            );
        }
    }
}
