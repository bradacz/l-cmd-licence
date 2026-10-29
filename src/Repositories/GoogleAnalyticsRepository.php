<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Services\TenantContextService;
use PDO;

final class GoogleAnalyticsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function findByAppId(int $appId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.*, a.name AS app_name, a.slug AS app_slug
             FROM google_analytics_integrations g
             INNER JOIN apps a ON a.id = g.app_id
             WHERE g.app_id = :app_id
             LIMIT 1'
        );
        $stmt->execute(['app_id' => $appId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByAppIdForTenant(int $appId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.*, a.name AS app_name, a.slug AS app_slug
             FROM google_analytics_integrations g
             INNER JOIN apps a ON a.id = g.app_id
             WHERE g.app_id = :app_id AND g.tenant_id = :tenant_id
             LIMIT 1'
        );
        $stmt->execute([
            'app_id' => $appId,
            'tenant_id' => $tenantId,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT g.*, a.name AS app_name, a.slug AS app_slug
             FROM google_analytics_integrations g
             INNER JOIN apps a ON a.id = g.app_id
             ORDER BY a.name ASC'
        )->fetchAll() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listAllForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.*, a.name AS app_name, a.slug AS app_slug
             FROM google_analytics_integrations g
             INNER JOIN apps a ON a.id = g.app_id
             WHERE g.tenant_id = :tenant_id
             ORDER BY a.name ASC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll() ?: [];
    }

    public function upsertForApp(int $appId, array $fields): void
    {
        $tenantId = (new TenantContextService())->tenantIdForAppId($appId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO google_analytics_integrations
             (tenant_id, app_id, is_enabled, measurement_id, website_url, property_name, admin_link, notes)
             VALUES (:tenant_id, :app_id, :is_enabled, :measurement_id, :website_url, :property_name, :admin_link, :notes)
             ON DUPLICATE KEY UPDATE
             tenant_id = VALUES(tenant_id),
             is_enabled = VALUES(is_enabled),
             measurement_id = VALUES(measurement_id),
             website_url = VALUES(website_url),
             property_name = VALUES(property_name),
             admin_link = VALUES(admin_link),
             notes = VALUES(notes),
             updated_at = NOW()'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'is_enabled' => !empty($fields['is_enabled']) ? 1 : 0,
            'measurement_id' => $fields['measurement_id'] ?? null,
            'website_url' => $fields['website_url'] ?? null,
            'property_name' => $fields['property_name'] ?? null,
            'admin_link' => $fields['admin_link'] ?? null,
            'notes' => $fields['notes'] ?? null,
        ]);
    }
}
