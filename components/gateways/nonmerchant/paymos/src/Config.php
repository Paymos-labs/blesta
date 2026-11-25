<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\ClientConfig;
use Paymos\Plugin\CredentialSet;

final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.paymos.io';
    public const ENV_LIVE = 'live';
    public const ENV_SANDBOX = 'sandbox';
    public const DEFAULT_BUTTON_TEXT = 'Pay with Paymos';
    public const MAX_BUTTON_TEXT_LENGTH = 100;

    /** @var string */
    private $mode;

    /** @var string */
    private $buttonText;

    /** @var array<string, array<string, mixed>> */
    private $environments;

    /**
     * @param array<string, array<string, mixed>> $environments
     */
    private function __construct(string $mode, string $buttonText, array $environments)
    {
        $this->mode = $mode;
        $this->buttonText = $buttonText;
        $this->environments = $environments;
    }

    /**
     * Keep only non-secret gateway settings; strip any legacy manual secret fields.
     *
     * @param array<string, mixed> $meta
     * @return array{mode: string, button_text: string}
     */
    public static function sanitizeMetaForStorage(array $meta): array
    {
        $rawMode = self::stringValue($meta, 'mode');
        if ($rawMode === '') {
            $rawMode = self::ENV_SANDBOX;
        }
        $mode = strtolower($rawMode);

        $buttonText = self::stringValue($meta, 'button_text');
        if ($buttonText === '') {
            $buttonText = self::DEFAULT_BUTTON_TEXT;
        }

        return array(
            'mode' => $mode,
            'button_text' => $buttonText,
        );
    }

    /**
     * Validate Blesta gateway settings (strictly Connect-bound: mode + button_text only).
     *
     * @param array<string, mixed> $meta
     * @return array<string, array{code: string, message: string}>
     */
    public static function validateMeta(array $meta): array
    {
        $errors = array();
        $sanitized = self::sanitizeMetaForStorage($meta);

        if (!in_array($sanitized['mode'], array(self::ENV_SANDBOX, self::ENV_LIVE), true)) {
            $errors['mode'] = array(
                'code' => 'Paymos.!error.mode.valid',
                'message' => 'Mode must be sandbox or live.',
            );
        }

        if (strlen($sanitized['button_text']) > self::MAX_BUTTON_TEXT_LENGTH) {
            $errors['button_text'] = array(
                'code' => 'Paymos.!error.button_text.length',
                'message' => 'Button text must be 100 characters or fewer.',
            );
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function fromStore(array $meta, CredentialStore $store): self
    {
        return self::fromCredentials($meta, $store->loadCredentials());
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $environments
     */
    public static function fromCredentials(array $meta, array $environments): self
    {
        $errors = self::validateMeta($meta);
        if (count($errors) > 0) {
            $messages = array();
            foreach ($errors as $error) {
                $messages[] = $error['message'];
            }
            throw new \InvalidArgumentException(implode(' ', $messages));
        }

        $sanitized = self::sanitizeMetaForStorage($meta);
        $normalized = CredentialSet::normalize($environments);

        if (count($normalized) === 0) {
            throw new \InvalidArgumentException('Paymos is not connected. Click Connect Paymos in the gateway settings.');
        }

        $mode = $sanitized['mode'];
        if (!isset($normalized[$mode])) {
            throw new \InvalidArgumentException('Paymos ' . $mode . ' credentials are not provisioned. Reconnect Paymos.');
        }

        $config = new self($mode, $sanitized['button_text'], $normalized);
        $secrets = $config->webhookSecrets();
        if (count($secrets) === 0 || !isset($secrets[$mode])) {
            throw new \InvalidArgumentException('Paymos ' . $mode . ' webhook secret is missing.');
        }

        return $config;
    }

    public function environment(): string
    {
        return $this->mode;
    }

    public function isSandbox(): bool
    {
        return $this->mode === self::ENV_SANDBOX;
    }

    public function buttonText(): string
    {
        return $this->buttonText;
    }

    public function hasEnvironment(string $environment): bool
    {
        $env = $this->normalizeEnvironment($environment);
        return isset($this->environments[$env]);
    }

    public function projectId(?string $environment = null): string
    {
        $env = $this->resolveEnvironment($environment);
        return (string) $this->environments[$env]['project_id'];
    }

    public function apiKey(?string $environment = null): string
    {
        $env = $this->resolveEnvironment($environment);
        return (string) $this->environments[$env]['api_key'];
    }

    public function apiSecret(?string $environment = null): string
    {
        $env = $this->resolveEnvironment($environment);
        return (string) $this->environments[$env]['api_secret'];
    }

    public function webhookSecret(?string $environment = null): string
    {
        $env = $this->resolveEnvironment($environment);
        return (string) $this->environments[$env]['webhook_secret'];
    }

    /**
     * @return array<string, string>
     */
    public function webhookSecrets(): array
    {
        $secrets = array();
        foreach (array(self::ENV_SANDBOX, self::ENV_LIVE) as $env) {
            if (isset($this->environments[$env]['webhook_secret'])
                && is_string($this->environments[$env]['webhook_secret'])
                && $this->environments[$env]['webhook_secret'] !== '') {
                $secrets[$env] = $this->environments[$env]['webhook_secret'];
            }
        }

        return $secrets;
    }

    public function baseUrl(?string $environment = null): string
    {
        $env = $this->resolveEnvironment($environment);
        $baseUrl = isset($this->environments[$env]['base_url']) && is_string($this->environments[$env]['base_url'])
            ? rtrim($this->environments[$env]['base_url'], '/')
            : '';

        return $baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL;
    }

    public function clientConfig(?string $environment = null, int $timeoutSeconds = 30): ClientConfig
    {
        $env = $this->resolveEnvironment($environment);
        return new ClientConfig(
            $this->apiKey($env),
            $this->apiSecret($env),
            $this->baseUrl($env),
            $timeoutSeconds
        );
    }

    public function sanitize(string $text): string
    {
        $secrets = array();
        foreach ($this->environments as $envData) {
            foreach (array('api_secret', 'webhook_secret') as $field) {
                if (isset($envData[$field]) && is_string($envData[$field]) && $envData[$field] !== '') {
                    $secrets[] = $envData[$field];
                }
            }
        }

        return self::sanitizeStatic($text, $secrets);
    }

    /**
     * @param array<int, string> $extraSecrets
     */
    public static function sanitizeStatic(string $text, array $extraSecrets = array()): string
    {
        $sanitized = $text;

        foreach ($extraSecrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $sanitized = str_replace($secret, '[REDACTED]', $sanitized);
            }
        }

        $sanitized = (string) preg_replace(
            '/(?:sk_live_|sk_test_|whsec_|rk_live_|rk_test_)[A-Za-z0-9_\-]{3,}/',
            '[REDACTED]',
            $sanitized
        );

        $sanitized = (string) preg_replace(
            '/(Authorization\s*:\s*HMAC-SHA256\s+)[^\s,;"\']+/i',
            '$1[REDACTED]',
            $sanitized
        );

        return $sanitized;
    }

    public static function isValidHttpsUrl(string $url, bool $allowQuery = false): bool
    {
        if (preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        if (!isset($parts['scheme']) || strtolower((string) $parts['scheme']) !== 'https') {
            return false;
        }

        if (!isset($parts['host']) || trim((string) $parts['host']) === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        if (!$allowQuery && isset($parts['query'])) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function resolveEnvironment(?string $environment): string
    {
        $env = $environment === null ? $this->mode : $this->normalizeEnvironment($environment);
        if (!isset($this->environments[$env])) {
            throw new \InvalidArgumentException('Paymos environment "' . $env . '" is not provisioned.');
        }

        return $env;
    }

    private function normalizeEnvironment(string $environment): string
    {
        $normalized = strtolower(trim($environment));
        if (!in_array($normalized, array(self::ENV_SANDBOX, self::ENV_LIVE), true)) {
            throw new \InvalidArgumentException('Paymos environment must be sandbox or live.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function stringValue(array $meta, string $key): string
    {
        if (!isset($meta[$key]) || !is_scalar($meta[$key])) {
            return '';
        }

        return trim((string) $meta[$key]);
    }
}
