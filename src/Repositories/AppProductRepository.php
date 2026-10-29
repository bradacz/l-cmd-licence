<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Services\TenantContextService;
use PDO;

final class AppProductRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<int,array<string,mixed>> */
    public function listForApp(int $appId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ap.id AS app_product_id, ap.is_active AS binding_active, p.*
             FROM app_products ap
             INNER JOIN products p ON p.id = ap.product_id
             WHERE ap.app_id = :app_id
             ORDER BY p.sort_order ASC, p.id ASC'
        );
        $stmt->execute(['app_id' => $appId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return int[] */
    public function listProductIdsForApp(int $appId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT product_id FROM app_products WHERE app_id = :app_id AND is_active = 1 ORDER BY product_id ASC'
        );
        $stmt->execute(['app_id' => $appId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_map('intval', $rows);
    }

    public function attach(int $appId, int $productId): void
    {
        $tenantId = (new TenantContextService())->tenantIdForAppId($appId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_products (tenant_id, app_id, product_id, is_active)
             VALUES (:tenant_id, :app_id, :product_id, 1)
             ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), is_active = 1'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'product_id' => $productId,
        ]);
    }

    public function detach(int $appId, int $productId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE app_products SET is_active = 0 WHERE app_id = :app_id AND product_id = :product_id'
        );
        $stmt->execute([
            'app_id' => $appId,
            'product_id' => $productId,
        ]);
    }
}
