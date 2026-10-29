<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Services\TenantContextService;
use PDO;

final class AppNotificationRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function profileForApp(int $appId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM app_notification_profiles WHERE app_id = :app_id LIMIT 1');
        $stmt->execute(['app_id' => $appId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsertProfile(int $appId, array $fields): void
    {
        $tenantId = (new TenantContextService())->tenantIdForAppId($appId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_notification_profiles
             (tenant_id, app_id, is_enabled, sender_name, reply_to_email, sales_email, ops_email, support_url, brand_color)
             VALUES (:tenant_id, :app_id, :is_enabled, :sender_name, :reply_to_email, :sales_email, :ops_email, :support_url, :brand_color)
             ON DUPLICATE KEY UPDATE
             tenant_id = VALUES(tenant_id),
             is_enabled = VALUES(is_enabled),
             sender_name = VALUES(sender_name),
             reply_to_email = VALUES(reply_to_email),
             sales_email = VALUES(sales_email),
             ops_email = VALUES(ops_email),
             support_url = VALUES(support_url),
             brand_color = VALUES(brand_color),
             updated_at = NOW()'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'is_enabled' => !empty($fields['is_enabled']) ? 1 : 0,
            'sender_name' => $fields['sender_name'] ?? null,
            'reply_to_email' => $fields['reply_to_email'] ?? null,
            'sales_email' => $fields['sales_email'] ?? null,
            'ops_email' => $fields['ops_email'] ?? null,
            'support_url' => $fields['support_url'] ?? null,
            'brand_color' => $fields['brand_color'] ?? null,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function templateOverridesForApp(int $appId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM app_email_template_overrides WHERE app_id = :app_id ORDER BY template_key ASC'
        );
        $stmt->execute(['app_id' => $appId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function templateOverrideForApp(int $appId, string $templateKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM app_email_template_overrides
             WHERE app_id = :app_id AND template_key = :template_key
             LIMIT 1'
        );
        $stmt->execute([
            'app_id' => $appId,
            'template_key' => $templateKey,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsertTemplateOverride(int $appId, string $templateKey, ?string $subject, ?string $body, bool $isEnabled = true): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_email_template_overrides (app_id, template_key, subject, body, is_enabled)
             VALUES (:app_id, :template_key, :subject, :body, :is_enabled)
             ON DUPLICATE KEY UPDATE
             subject = VALUES(subject),
             body = VALUES(body),
             is_enabled = VALUES(is_enabled),
             updated_at = NOW()'
        );
        $stmt->execute([
            'app_id' => $appId,
            'template_key' => $templateKey,
            'subject' => $subject,
            'body' => $body,
            'is_enabled' => $isEnabled ? 1 : 0,
        ]);
    }
}
