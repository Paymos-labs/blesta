<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\CredentialSet;

final class CredentialStore
{
    public const TABLE = 'paymos_settings';
    public const CREDENTIALS_KEY = 'credentials_v1';
    public const STATE_KEY = 'connect_state_v1';
    public const INSTALLATION_ID_KEY = 'installation_id_v1';

    private const CREDENTIALS_AAD = 'paymos-blesta-credentials-v1';
    private const STATE_AAD = 'paymos-blesta-connect-state-v1';

    /** @var DbDriverInterface */
    private $db;

    /** @var callable|null */
    private $keyMaterialProvider;

    /** @var callable */
    private $clock;

    /** @var bool */
    private $schemaEnsured = false;

    public function __construct(
        DbDriverInterface $db,
        ?callable $keyMaterialProvider = null,
        ?callable $clock = null
    ) {
        $this->db = $db;
        $this->keyMaterialProvider = $keyMaterialProvider;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        if ($this->db->tableExists(self::TABLE)) {
            $this->schemaEnsured = true;
            return;
        }

        if ($this->db->inTransaction()) {
            return;
        }

        $valueColumn = $this->db->driverName() === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'setting_key VARCHAR(128) PRIMARY KEY, '
            . 'setting_value ' . $valueColumn . ' NOT NULL, '
            . 'updated_at BIGINT NOT NULL'
            . ')'
        );

        $this->schemaEnsured = true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadCredentials(): array
    {
        $payload = $this->loadEncrypted(self::CREDENTIALS_KEY, self::CREDENTIALS_AAD);
        if (count($payload) === 0) {
            return array();
        }

        if (!isset($payload['schema'], $payload['environments'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['environments'])) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }

        return CredentialSet::normalize($payload['environments']);
    }

    /**
     * @param array<string, mixed> $environments
     */
    public function saveCredentials(array $environments): void
    {
        $normalized = CredentialSet::normalize($environments);
        if (count($normalized) === 0) {
            throw new \InvalidArgumentException('Paymos credentials are incomplete.');
        }

        $this->saveEncrypted(self::CREDENTIALS_KEY, self::CREDENTIALS_AAD, array(
            'schema' => 1,
            'environments' => $normalized,
        ));
    }

    public function hasCredentials(): bool
    {
        return count($this->loadCredentials()) > 0;
    }

    /**
     * @return array{project_id: string, capabilities: array<int, string>, refund_contract_version: ?int, refunds_enabled: bool}
     */
    public function refundConfig(string $environment): array
    {
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            throw new \InvalidArgumentException('refund_environment_invalid');
        }

        $credentials = $this->loadCredentials();
        $values = isset($credentials[$environment]) && is_array($credentials[$environment])
            ? $credentials[$environment]
            : array();

        return array(
            'project_id' => isset($values['project_id']) && is_string($values['project_id'])
                ? $values['project_id']
                : '',
            'capabilities' => CredentialSet::hasCapability($values, 'invoice_refunds_v1')
                ? array('invoice_refunds_v1')
                : array(),
            'refund_contract_version' => isset($values['refund_contract_version']) && is_int($values['refund_contract_version'])
                ? $values['refund_contract_version']
                : null,
            'refunds_enabled' => CredentialSet::refundsEnabled($values),
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveState(array $state): void
    {
        $expiresIn = isset($state['expires_in']) ? max(1, (int) $state['expires_in']) : 600;
        $this->saveEncrypted(self::STATE_KEY, self::STATE_AAD, array(
            'schema' => 1,
            'expires_at' => $this->now() + $expiresIn,
            'state' => $state,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function loadState(): array
    {
        $payload = $this->loadEncrypted(self::STATE_KEY, self::STATE_AAD);
        if (count($payload) === 0) {
            return array();
        }

        if (!isset($payload['schema'], $payload['expires_at'], $payload['state'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['state'])
            || $this->now() >= (int) $payload['expires_at']) {
            $this->clearState();
            return array();
        }

        return $payload['state'];
    }

    public function clearState(): void
    {
        $this->deleteSetting(self::STATE_KEY);
    }

    public function installationId(): string
    {
        $existing = $this->getSetting(self::INSTALLATION_ID_KEY);
        if ($existing === null || $existing === '') {
            $this->ensureSchema();
            $candidate = self::uuidV4();
            $this->db->insertUnique(self::TABLE, array(
                'setting_key' => self::INSTALLATION_ID_KEY,
                'setting_value' => $candidate,
                'updated_at' => $this->now(),
            ), 'setting_key');
            $existing = $this->getSetting(self::INSTALLATION_ID_KEY);
        }

        if (!is_string($existing) || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $existing) !== 1) {
            throw new \RuntimeException('refund_installation_missing');
        }

        return $existing;
    }

    public function getSetting(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        $this->ensureSchema();
        $row = $this->db->fetchOne(
            'SELECT setting_value FROM ' . self::TABLE . ' WHERE setting_key = ?',
            array($key)
        );

        if ($row === null || !isset($row['setting_value']) || !is_string($row['setting_value'])) {
            return null;
        }

        return $row['setting_value'];
    }

    public function putSetting(string $key, string $value): void
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('Setting key must be non-empty.');
        }

        $this->ensureSchema();
        $now = $this->now();
        $inserted = $this->db->insertUnique(self::TABLE, array(
            'setting_key' => $key,
            'setting_value' => $value,
            'updated_at' => $now,
        ), 'setting_key');

        if (!$inserted) {
            $this->db->casUpdate(
                self::TABLE,
                array(
                    'setting_value' => $value,
                    'updated_at' => $now,
                ),
                array('setting_key' => $key)
            );
        }
    }

    public function deleteSetting(string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }

        $this->ensureSchema();
        $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE setting_key = ?',
            array($key)
        );
    }

    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8)
            . '-' . substr($hex, 8, 4)
            . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4)
            . '-' . substr($hex, 20, 12);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEncrypted(string $key, string $aad): array
    {
        $encoded = $this->getSetting($key);
        if ($encoded === null || $encoded === '') {
            return array();
        }

        return AesGcmEnvelope::open($encoded, $this->keyMaterial(), $aad);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function saveEncrypted(string $key, string $aad, array $payload): void
    {
        $encoded = AesGcmEnvelope::seal($payload, $this->keyMaterial(), $aad);
        $this->putSetting($key, $encoded);
    }

    private function keyMaterial(): string
    {
        $raw = null;
        if ($this->keyMaterialProvider !== null) {
            $raw = call_user_func($this->keyMaterialProvider);
        } elseif (class_exists('Configure') && method_exists('Configure', 'get')) {
            $raw = \Configure::get('Blesta.system_key');
        }

        $material = is_scalar($raw) ? trim((string) $raw) : '';
        if ($material === '') {
            throw new \RuntimeException('Blesta encryption key (Blesta.system_key) is not configured.');
        }

        return $material;
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
