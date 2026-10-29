<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Services\TenantContextService;
use App\Support\Json;
use PDO;

final class PolicyRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<int,array<string,mixed>> */
    public function listSystemPolicies(): array
    {
        return $this->pdo->query('SELECT * FROM system_policies ORDER BY policy_key ASC')->fetchAll() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listAppPolicies(): array
    {
        return $this->pdo->query(
            'SELECT ap.*, a.name AS app_name, a.slug AS app_slug
             FROM app_policies ap
             INNER JOIN apps a ON a.id = ap.app_id
             ORDER BY a.name ASC, ap.policy_key ASC'
        )->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function findSystemPolicy(string $policyKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM system_policies WHERE policy_key = :policy_key LIMIT 1'
        );
        $stmt->execute(['policy_key' => $policyKey]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findAppPolicy(int $appId, string $policyKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM app_policies
             WHERE app_id = :app_id AND policy_key = :policy_key
             LIMIT 1'
        );
        $stmt->execute([
            'app_id' => $appId,
            'policy_key' => $policyKey,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsertSystemPolicy(string $policyKey, mixed $policyValue, bool $isEnabled): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO system_policies (policy_key, policy_value, is_enabled)
             VALUES (:policy_key, :policy_value, :is_enabled)
             ON DUPLICATE KEY UPDATE
             policy_value = VALUES(policy_value),
             is_enabled = VALUES(is_enabled),
             updated_at = NOW()'
        );
        $stmt->execute([
            'policy_key' => $policyKey,
            'policy_value' => Json::encode($policyValue),
            'is_enabled' => $isEnabled ? 1 : 0,
        ]);
    }

    public function upsertAppPolicy(int $appId, string $policyKey, mixed $policyValue, bool $isEnabled): void
    {
        $tenantId = (new TenantContextService())->tenantIdForAppId($appId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_policies (tenant_id, app_id, policy_key, policy_value, is_enabled)
             VALUES (:tenant_id, :app_id, :policy_key, :policy_value, :is_enabled)
             ON DUPLICATE KEY UPDATE
             tenant_id = VALUES(tenant_id),
             policy_value = VALUES(policy_value),
             is_enabled = VALUES(is_enabled),
             updated_at = NOW()'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'policy_key' => $policyKey,
            'policy_value' => Json::encode($policyValue),
            'is_enabled' => $isEnabled ? 1 : 0,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listProductPoliciesForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pp.*, p.name AS product_name, p.slug AS product_slug
             FROM product_policies pp
             INNER JOIN products p ON p.id = pp.product_id
             WHERE pp.tenant_id = :tenant_id
             ORDER BY p.name ASC, pp.policy_key ASC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listTariffPoliciesForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tp.*, t.product_id
             FROM tariff_policies tp
             INNER JOIN product_tariffs t ON t.id = tp.tariff_id
             WHERE tp.tenant_id = :tenant_id
             ORDER BY tp.tariff_id ASC, tp.policy_key ASC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function findProductPolicy(int $productId, string $policyKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM product_policies
             WHERE product_id = :product_id AND policy_key = :policy_key
             LIMIT 1'
        );
        $stmt->execute([
            'product_id' => $productId,
            'policy_key' => $policyKey,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findTariffPolicy(int $tariffId, string $policyKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tariff_policies
             WHERE tariff_id = :tariff_id AND policy_key = :policy_key
             LIMIT 1'
        );
        $stmt->execute([
            'tariff_id' => $tariffId,
            'policy_key' => $policyKey,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsertProductPolicy(int $productId, string $policyKey, mixed $policyValue, bool $isEnabled): void
    {
        $tenantId = (new TenantContextService())->tenantIdForProductId($productId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_policies (tenant_id, product_id, policy_key, policy_value, is_enabled)
             VALUES (:tenant_id, :product_id, :policy_key, :policy_value, :is_enabled)
             ON DUPLICATE KEY UPDATE
             tenant_id = VALUES(tenant_id),
             policy_value = VALUES(policy_value),
             is_enabled = VALUES(is_enabled),
             updated_at = NOW()'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'policy_key' => $policyKey,
            'policy_value' => Json::encode($policyValue),
            'is_enabled' => $isEnabled ? 1 : 0,
        ]);
    }

    public function upsertTariffPolicy(int $tariffId, string $policyKey, mixed $policyValue, bool $isEnabled): void
    {
        $tenantId = (new TenantContextService())->tenantIdForTariffId($tariffId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO tariff_policies (tenant_id, tariff_id, policy_key, policy_value, is_enabled)
             VALUES (:tenant_id, :tariff_id, :policy_key, :policy_value, :is_enabled)
             ON DUPLICATE KEY UPDATE
             tenant_id = VALUES(tenant_id),
             policy_value = VALUES(policy_value),
             is_enabled = VALUES(is_enabled),
             updated_at = NOW()'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'tariff_id' => $tariffId,
            'policy_key' => $policyKey,
            'policy_value' => Json::encode($policyValue),
            'is_enabled' => $isEnabled ? 1 : 0,
        ]);
    }
}
