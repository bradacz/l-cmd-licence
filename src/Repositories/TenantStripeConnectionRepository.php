<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\SecretBox;
use PDO;

final class TenantStripeConnectionRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function findByTenantId(int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenant_stripe_connections WHERE tenant_id = :tenant_id LIMIT 1');
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $secretKeyEncrypted = $row['secret_key_encrypted'] ?? null;
        $webhookSecretEncrypted = $row['webhook_secret_encrypted'] ?? null;
        $secretKey = SecretBox::decrypt($secretKeyEncrypted) ?? '';
        $webhookSecret = SecretBox::decrypt($webhookSecretEncrypted) ?? '';

        if (
            (SecretBox::needsReencrypt(is_string($secretKeyEncrypted) ? $secretKeyEncrypted : null) && $secretKey !== '')
            || (SecretBox::needsReencrypt(is_string($webhookSecretEncrypted) ? $webhookSecretEncrypted : null) && $webhookSecret !== '')
        ) {
            // A read must not recurse through upsert -> findByTenantId.
            $update = $this->pdo->prepare('UPDATE tenant_stripe_connections
                SET secret_key_encrypted = :secret, webhook_secret_encrypted = :webhook
                WHERE tenant_id = :tenant_id AND secret_key_encrypted <=> :old_secret
                    AND webhook_secret_encrypted <=> :old_webhook');
            $update->execute([
                'secret' => $secretKey !== '' ? SecretBox::encrypt($secretKey) : $secretKeyEncrypted,
                'webhook' => $webhookSecret !== '' ? SecretBox::encrypt($webhookSecret) : $webhookSecretEncrypted,
                'tenant_id' => $tenantId,
                'old_secret' => $secretKeyEncrypted,
                'old_webhook' => $webhookSecretEncrypted,
            ]);
        }

        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'publishable_key' => (string) ($row['publishable_key'] ?? ''),
            'secret_key' => $secretKey,
            'webhook_secret' => $webhookSecret,
            'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
        ];
    }

    public function upsert(int $tenantId, array $fields): void
    {
        $current = $this->findByTenantId($tenantId) ?? [];
        $publishableKey = array_key_exists('publishable_key', $fields) ? trim((string) $fields['publishable_key']) : (string) ($current['publishable_key'] ?? '');
        $secretKey = array_key_exists('secret_key', $fields) ? trim((string) $fields['secret_key']) : (string) ($current['secret_key'] ?? '');
        $webhookSecret = array_key_exists('webhook_secret', $fields) ? trim((string) $fields['webhook_secret']) : (string) ($current['webhook_secret'] ?? '');
        $isEnabled = array_key_exists('is_enabled', $fields) ? ((bool) $fields['is_enabled'] ? 1 : 0) : ((int) (($current['is_enabled'] ?? false) ? 1 : 0));

        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_stripe_connections (tenant_id, publishable_key, secret_key_encrypted, webhook_secret_encrypted, is_enabled)
             VALUES (:tenant_id, :publishable_key, :secret_key_encrypted, :webhook_secret_encrypted, :is_enabled)
             ON DUPLICATE KEY UPDATE
                publishable_key = VALUES(publishable_key),
                secret_key_encrypted = VALUES(secret_key_encrypted),
                webhook_secret_encrypted = VALUES(webhook_secret_encrypted),
                is_enabled = VALUES(is_enabled),
                updated_at = NOW()'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'publishable_key' => $publishableKey !== '' ? $publishableKey : null,
            'secret_key_encrypted' => SecretBox::encrypt($secretKey),
            'webhook_secret_encrypted' => SecretBox::encrypt($webhookSecret),
            'is_enabled' => $isEnabled,
        ]);
    }

    /** @return array<int,array{tenant_id:int,secret:string}> */
    public function enabledWebhookSecrets(): array
    {
        $stmt = $this->pdo->query(
            'SELECT tenant_id, webhook_secret_encrypted
             FROM tenant_stripe_connections
             WHERE is_enabled = 1 AND webhook_secret_encrypted IS NOT NULL'
        );

        $secrets = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $secret = SecretBox::decrypt($row['webhook_secret_encrypted'] ?? null);
            if (is_string($secret) && trim($secret) !== '') {
                $secrets[] = ['tenant_id' => (int) $row['tenant_id'], 'secret' => trim($secret)];
            }
        }

        return $secrets;
    }
}
