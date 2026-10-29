<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Services\TenantContextService;
use App\Support\Json;
use App\Support\SecretBox;
use PDO;

final class AISettingsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function findByModuleKey(string $moduleKey): ?array
    {
        $tenantId = (new TenantContextService())->defaultTenantId();
        return $this->findByModuleKeyForTenant($tenantId, $moduleKey);
    }

    /** @return array<string,mixed>|null */
    public function findByModuleKeyForTenant(int $tenantId, string $moduleKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ai_provider_settings WHERE tenant_id = :tenant_id AND module_key = :module_key LIMIT 1'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'module_key' => $moduleKey,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $apiKeyEncrypted = (string) ($row['api_key_encrypted'] ?? '');
        $row['api_key'] = SecretBox::decrypt($apiKeyEncrypted);
        $row['enabled_app_ids'] = Json::decodeObject((string) ($row['enabled_app_ids_json'] ?? '[]'));

        if (SecretBox::needsReencrypt($apiKeyEncrypted) && is_string($row['api_key']) && $row['api_key'] !== '') {
            $this->upsertByModuleKey($moduleKey, [
                'tenant_id' => $tenantId,
                'is_enabled' => !empty($row['is_enabled']),
                'provider_name' => $row['provider_name'] ?? null,
                'model_name' => $row['model_name'] ?? null,
                'api_key' => $row['api_key'],
                'daily_request_limit' => (int) ($row['daily_request_limit'] ?? 50),
                'store_prompts' => !empty($row['store_prompts']),
                'redact_sensitive_data' => !empty($row['redact_sensitive_data']),
                'enabled_app_ids' => $row['enabled_app_ids'],
            ]);
        }

        return $row;
    }

    public function upsertByModuleKey(string $moduleKey, array $fields): void
    {
        $tenantId = (int) ($fields['tenant_id'] ?? (new TenantContextService())->defaultTenantId());

        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_provider_settings
             (tenant_id, module_key, is_enabled, provider_name, model_name, api_key_encrypted, daily_request_limit, store_prompts, redact_sensitive_data, enabled_app_ids_json)
             VALUES (:tenant_id, :module_key, :is_enabled, :provider_name, :model_name, :api_key_encrypted, :daily_request_limit, :store_prompts, :redact_sensitive_data, :enabled_app_ids_json)
             ON DUPLICATE KEY UPDATE
             tenant_id = VALUES(tenant_id),
             is_enabled = VALUES(is_enabled),
             provider_name = VALUES(provider_name),
             model_name = VALUES(model_name),
             api_key_encrypted = VALUES(api_key_encrypted),
             daily_request_limit = VALUES(daily_request_limit),
             store_prompts = VALUES(store_prompts),
             redact_sensitive_data = VALUES(redact_sensitive_data),
             enabled_app_ids_json = VALUES(enabled_app_ids_json),
             updated_at = NOW()'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'module_key' => $moduleKey,
            'is_enabled' => !empty($fields['is_enabled']) ? 1 : 0,
            'provider_name' => $fields['provider_name'] ?? null,
            'model_name' => $fields['model_name'] ?? null,
            'api_key_encrypted' => SecretBox::encrypt($fields['api_key'] ?? null),
            'daily_request_limit' => (int) ($fields['daily_request_limit'] ?? 50),
            'store_prompts' => !empty($fields['store_prompts']) ? 1 : 0,
            'redact_sensitive_data' => !empty($fields['redact_sensitive_data']) ? 1 : 0,
            'enabled_app_ids_json' => Json::encode(array_values($fields['enabled_app_ids'] ?? [])),
        ]);
    }
}
