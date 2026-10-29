<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class TenantRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function defaultTenant(): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM tenants
             ORDER BY is_system DESC, created_at ASC, id ASC
             LIMIT 1"
        );
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function defaultTenantId(): int
    {
        $tenant = $this->defaultTenant();
        if ($tenant === null) {
            throw new \RuntimeException('No tenant configured.');
        }

        return (int) $tenant['id'];
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM apps a WHERE a.tenant_id = t.id) AS app_count,
                    (SELECT COUNT(*) FROM products p WHERE p.tenant_id = t.id) AS product_count,
                    (SELECT COUNT(*) FROM licenses l WHERE l.tenant_id = t.id) AS license_count
             FROM tenants t
             ORDER BY t.is_system DESC, t.created_at ASC, t.id ASC'
        )->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenants (slug, name, status, plan_key, timezone, default_currency, is_system, is_demo)
             VALUES (:slug, :name, :status, :plan_key, :timezone, :default_currency, :is_system, :is_demo)'
        );
        $stmt->execute([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'status' => $data['status'] ?? 'active',
            'plan_key' => $data['plan_key'] ?? 'free',
            'timezone' => $data['timezone'] ?? 'Europe/Prague',
            'default_currency' => $data['default_currency'] ?? 'EUR',
            'is_system' => !empty($data['is_system']) ? 1 : 0,
            'is_demo' => !empty($data['is_demo']) ? 1 : 0,
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

        $sql = 'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function tenantIdForAppId(int $appId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT tenant_id FROM apps WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $appId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }

    public function tenantIdForProductId(int $productId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT tenant_id FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $productId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }

    public function tenantIdForTariffId(int $tariffId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT tenant_id FROM product_tariffs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $tariffId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }

    public function tenantIdForLicenseId(int $licenseId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT tenant_id FROM licenses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $licenseId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }
}
