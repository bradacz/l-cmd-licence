<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Services\TenantContextService;
use PDO;

final class AppRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT * FROM apps ORDER BY is_active DESC, name ASC, id ASC'
        )->fetchAll() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listAllForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*,
                    COUNT(DISTINCT ap.product_id) AS product_count,
                    COUNT(DISTINCT ak.id) AS key_count
             FROM apps a
             LEFT JOIN app_products ap ON ap.app_id = a.id AND ap.is_active = 1
             LEFT JOIN app_keys ak ON ak.app_id = a.id AND ak.is_active = 1
             WHERE a.tenant_id = :tenant_id
             GROUP BY a.id
             ORDER BY a.is_active DESC, a.name ASC, a.id ASC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll() ?: [];
    }

    public function countForTenant(int $tenantId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM apps WHERE tenant_id = :tenant_id');
        $stmt->execute(['tenant_id' => $tenantId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM apps WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByIdForTenant(int $id, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM apps WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM apps WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $tenantId = (int) ($data['tenant_id'] ?? (new TenantContextService())->defaultTenantId());

        $stmt = $this->pdo->prepare(
            'INSERT INTO apps (
                tenant_id,
                slug,
                name,
                description,
                platform_type,
                base_url,
                public_base_url,
                checkout_success_url,
                checkout_cancel_url,
                support_email,
                notification_email,
                default_currency,
                enabled_currencies,
                is_active
             ) VALUES (
                :tenant_id,
                :slug,
                :name,
                :description,
                :platform_type,
                :base_url,
                :public_base_url,
                :checkout_success_url,
                :checkout_cancel_url,
                :support_email,
                :notification_email,
                :default_currency,
                :enabled_currencies,
                :is_active
             )'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'platform_type' => $data['platform_type'] ?? null,
            'base_url' => $data['base_url'] ?? null,
            'public_base_url' => $data['public_base_url'] ?? null,
            'checkout_success_url' => $data['checkout_success_url'] ?? null,
            'checkout_cancel_url' => $data['checkout_cancel_url'] ?? null,
            'support_email' => $data['support_email'] ?? null,
            'notification_email' => $data['notification_email'] ?? null,
            'default_currency' => $data['default_currency'] ?? null,
            'enabled_currencies' => $data['enabled_currencies'] ?? null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $key => $value) {
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $sql = 'UPDATE apps SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    /** @return array<int,array<string,mixed>> */
    public function listWithMetrics(): array
    {
        return $this->pdo->query(
            "SELECT a.*,
                    COUNT(DISTINCT ap.product_id) AS product_count,
                    COUNT(DISTINCT ak.id) AS key_count
             FROM apps a
             LEFT JOIN app_products ap ON ap.app_id = a.id AND ap.is_active = 1
             LEFT JOIN app_keys ak ON ak.app_id = a.id AND ak.is_active = 1
             GROUP BY a.id
             ORDER BY a.is_active DESC, a.name ASC, a.id ASC"
        )->fetchAll() ?: [];
    }
}
