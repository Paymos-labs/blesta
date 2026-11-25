<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Connect\DeviceConnectClient;
use Paymos\Plugin\CredentialSet;

final class ConnectHandler
{
    public const PLUGIN_SLUG = 'blesta';
    public const CONNECT_BASE_URL = 'https://app.paymos.io';
    public const CALLBACK_PATH = '/callback/gw/1/paymos/';

    /** @var CredentialStore */
    private $credentialStore;

    /** @var DeviceConnectClient */
    private $connectClient;

    /** @var callable|null */
    private $authorize;

    public function __construct(
        CredentialStore $credentialStore,
        ?DeviceConnectClient $connectClient = null,
        ?callable $authorize = null
    ) {
        $this->credentialStore = $credentialStore;
        $this->connectClient = $connectClient ?: new DeviceConnectClient(self::CONNECT_BASE_URL);
        $this->authorize = $authorize;
    }

    /**
     * Initiate RFC 8628 device bootstrap for Blesta.
     *
     * @return array{verification_url: string, user_code: string, interval: int}
     */
    public function start(string $sourceUrl, string $returnUrl = ''): array
    {
        $this->enforceAuthorization();

        $normalizedSource = $this->normalizeAndValidateSourceUrl($sourceUrl);
        $normalizedReturn = trim($returnUrl);
        if ($normalizedReturn !== '' && !Config::isValidHttpsUrl($normalizedReturn, true)) {
            $normalizedReturn = '';
        }

        $state = $this->connectClient->start(self::PLUGIN_SLUG, $normalizedSource, $normalizedReturn);
        $this->credentialStore->saveState($state);

        return array(
            'verification_url' => (string) $state['verification_url'],
            'user_code' => (string) $state['user_code'],
            'interval' => isset($state['interval']) ? max(1, (int) $state['interval']) : 5,
        );
    }

    /**
     * Poll the active device flow and atomically persist Sandbox + Live credentials.
     *
     * @return array{status: string}
     */
    public function poll(string $sourceUrl): array
    {
        $this->enforceAuthorization();

        $normalizedSource = $this->normalizeAndValidateSourceUrl($sourceUrl);
        $state = $this->credentialStore->loadState();
        if (!isset($state['device_code']) || !is_string($state['device_code']) || trim($state['device_code']) === '') {
            throw new \RuntimeException('No active Paymos connection request.');
        }

        if (isset($state['cms']) && (string) $state['cms'] !== self::PLUGIN_SLUG) {
            $this->credentialStore->clearState();
            throw new \RuntimeException('Stored Paymos connection state does not match Blesta.');
        }

        if (isset($state['source_url']) && rtrim((string) $state['source_url'], '/') !== $normalizedSource) {
            $this->credentialStore->clearState();
            throw new \RuntimeException('Blesta installation URL changed during Paymos connection.');
        }

        $result = $this->connectClient->poll($state['device_code']);
        $status = isset($result['status']) && is_string($result['status']) ? $result['status'] : '';

        if ($status === 'connected') {
            $plugin = isset($result['plugin']) && is_string($result['plugin']) ? $result['plugin'] : '';
            $returnedSource = isset($result['source_url']) && is_string($result['source_url'])
                ? rtrim(trim($result['source_url']), '/')
                : '';

            if ($plugin !== self::PLUGIN_SLUG || $returnedSource !== $normalizedSource) {
                $this->credentialStore->clearState();
                throw new \RuntimeException('Paymos connection response does not match this Blesta installation.');
            }

            $credentials = isset($result['credentials']) && is_array($result['credentials'])
                ? $result['credentials']
                : array();
            $normalized = CredentialSet::normalize($credentials);
            if (count($normalized) === 0) {
                $this->credentialStore->clearState();
                throw new \RuntimeException('Paymos returned incomplete credentials.');
            }

            $this->credentialStore->saveCredentials($normalized);
            $this->credentialStore->clearState();

            return array('status' => 'connected');
        }

        if (in_array($status, array('authorization_pending', 'slow_down'), true)) {
            return array('status' => $status);
        }

        $this->credentialStore->clearState();
        throw new \RuntimeException('Paymos connection was denied or expired.');
    }

    private function enforceAuthorization(): void
    {
        if ($this->authorize !== null) {
            call_user_func($this->authorize);
        }
    }

    private function normalizeAndValidateSourceUrl(string $sourceUrl): string
    {
        $normalized = rtrim(trim($sourceUrl), '/');
        if ($normalized === '' || !Config::isValidHttpsUrl($normalized, false)) {
            throw new \InvalidArgumentException('Blesta URL must use HTTPS.');
        }

        return $normalized;
    }
}
