<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Services\TenantContextService;
use App\Support\CurrencyCatalog;
use PDO;

final class ProductRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<int,array<string,mixed>> */
    public function listActive(): array
    {
        return $this->listActiveForProductIds(null, null);
    }

    /** @param int[]|null $productIds
      * @return array<int,array<string,mixed>> */
    public function listActiveForProductIds(?array $productIds, ?string $currency = null): array
    {
        $where = 'WHERE p.is_active = 1';
        $params = [];

        if (is_array($productIds)) {
            $productIds = array_values(array_unique(array_map('intval', $productIds)));
            if ($productIds === []) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $where .= " AND p.id IN ({$placeholders})";
            $params = $productIds;
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.slug, p.name, p.description
             FROM products p
             {$where}
             ORDER BY p.sort_order ASC, p.id ASC"
        );
        $stmt->execute($params);
        $products = $stmt->fetchAll() ?: [];

        if ($products === []) {
            return [];
        }

        $ids = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, product_id, license_type, stripe_price_id, purchase_kind, target_plan_key, price, currency, is_recommended, is_default, sort_order
             FROM product_tariffs WHERE product_id IN ({$placeholders}) AND is_active = 1
             ORDER BY is_default DESC, is_recommended DESC, sort_order ASC, id ASC"
        );
        $stmt->execute($ids);
        $tariffs = $stmt->fetchAll() ?: [];

        $requestedCurrency = CurrencyCatalog::normalize($currency);
        $tariffsByProduct = [];
        foreach ($tariffs as $t) {
            $tariffsByProduct[$t['product_id']][] = $this->mapTariff($t);
        }

        $result = [];
        foreach ($products as $p) {
            $productTariffs = $tariffsByProduct[$p['id']] ?? [];
            $selectedTariffs = $this->selectTariffsForCurrency($productTariffs, $requestedCurrency);

            $result[] = [
                'id' => $p['slug'],
                'name' => $p['name'],
                'description' => $p['description'],
                'defaultCurrency' => $this->defaultCurrencyForTariffs($productTariffs),
                'tariffs' => $selectedTariffs,
            ];
        }

        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT * FROM products ORDER BY sort_order ASC, id ASC'
        )->fetchAll() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listAllForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*,
                    COUNT(t.id) AS tariff_count,
                    SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) AS active_tariff_count
             FROM products p
             LEFT JOIN product_tariffs t ON t.product_id = p.id
             WHERE p.tenant_id = :tenant_id
             GROUP BY p.id
             ORDER BY p.sort_order ASC, p.id ASC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listAllWithTariffCounts(): array
    {
        return $this->pdo->query(
            'SELECT p.*,
                    COUNT(t.id) AS tariff_count,
                    SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) AS active_tariff_count
             FROM products p
             LEFT JOIN product_tariffs t ON t.product_id = p.id
             GROUP BY p.id
             ORDER BY p.sort_order ASC, p.id ASC'
        )->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByIdForTenant(int $id, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findBySlugForTenant(string $slug, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE slug = :slug AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute([
            'slug' => $slug,
            'tenant_id' => $tenantId,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(string $slug, string $name, ?string $description, int $sortOrder = 0, ?int $tenantId = null): int
    {
        $resolvedTenantId = $tenantId ?? (new TenantContextService())->defaultTenantId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO products (tenant_id, slug, name, description, sort_order) VALUES (:tenant_id, :slug, :name, :description, :sort_order)'
        );
        $stmt->execute([
            'tenant_id' => $resolvedTenantId,
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'sort_order' => $sortOrder,
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

        $sql = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    /** @return array<int,array<string,mixed>> */
    public function getTariffs(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM product_tariffs WHERE product_id = :product_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['product_id' => $productId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function getTariffsForTenant(int $productId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*
             FROM product_tariffs t
             INNER JOIN products p ON p.id = t.product_id
             WHERE t.product_id = :product_id AND p.tenant_id = :tenant_id
             ORDER BY t.sort_order ASC, t.id ASC'
        );
        $stmt->execute([
            'product_id' => $productId,
            'tenant_id' => $tenantId,
        ]);

        return $stmt->fetchAll() ?: [];
    }

    public function createTariff(
        int $productId,
        string $licenseType,
        string $stripePriceId,
        string $purchaseKind,
        ?string $targetPlanKey,
        float $price,
        string $currency,
        bool $isRecommended,
        bool $isDefault,
        int $sortOrder = 0
    ): int
    {
        $normalizedCurrency = CurrencyCatalog::normalize($currency) ?? 'EUR';
        $tenantId = (new TenantContextService())->tenantIdForProductId($productId);
        if ($isDefault) {
            $this->clearDefaultTariffs($productId);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_tariffs (tenant_id, product_id, license_type, stripe_price_id, purchase_kind, target_plan_key, price, currency, is_recommended, is_default, sort_order)
             VALUES (:tenant_id, :product_id, :license_type, :stripe_price_id, :purchase_kind, :target_plan_key, :price, :currency, :is_recommended, :is_default, :sort_order)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'license_type' => $licenseType,
            'stripe_price_id' => $stripePriceId,
            'purchase_kind' => $purchaseKind,
            'target_plan_key' => $targetPlanKey,
            'price' => $price,
            'currency' => $normalizedCurrency,
            'is_recommended' => $isRecommended ? 1 : 0,
            'is_default' => $isDefault ? 1 : 0,
            'sort_order' => $sortOrder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findTariffByIdForTenant(int $id, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*
             FROM product_tariffs t
             INNER JOIN products p ON p.id = t.product_id
             WHERE t.id = :id AND p.tenant_id = :tenant_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findActiveTariffByPriceId(string $priceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, p.slug AS product_slug, p.name AS product_name
             FROM product_tariffs t
             INNER JOIN products p ON p.id = t.product_id
             WHERE t.stripe_price_id = :price_id
               AND t.is_active = 1
               AND p.is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['price_id' => $priceId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateTariff(int $id, array $fields): void
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

        $sql = 'UPDATE product_tariffs SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function deleteTariff(int $id): void
    {
        $this->pdo->prepare('DELETE FROM product_tariffs WHERE id = :id')->execute(['id' => $id]);
    }

    public function clearDefaultTariffs(int $productId): void
    {
        $this->pdo->prepare(
            'UPDATE product_tariffs SET is_default = 0 WHERE product_id = :product_id'
        )->execute(['product_id' => $productId]);
    }

    /** @param int[] $productIds
      * @return array<int,array<string,mixed>> */
    public function listUnboundForApp(array $productIds): array
    {
        if ($productIds === []) {
            return $this->listAll();
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM products WHERE id NOT IN ({$placeholders}) ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute(array_values($productIds));

        return $stmt->fetchAll() ?: [];
    }

    /** @param array<string,mixed> $tariff
      * @return array<string,mixed> */
    private function mapTariff(array $tariff): array
    {
        return [
            'licenseType' => $tariff['license_type'],
            'priceId' => $tariff['stripe_price_id'],
            'purchaseKind' => (string) ($tariff['purchase_kind'] ?? 'license'),
            'targetPlanKey' => $tariff['target_plan_key'] !== null ? (string) $tariff['target_plan_key'] : null,
            'price' => (float) $tariff['price'],
            'currency' => $tariff['currency'],
            'period' => match ($tariff['license_type']) {
                'monthly' => 'mesic',
                'annual' => 'rok',
                default => null,
            },
            'recommended' => (bool) $tariff['is_recommended'],
            'default' => (bool) ($tariff['is_default'] ?? false),
        ];
    }

    /** @param array<int,array<string,mixed>> $tariffs
      * @return array<int,array<string,mixed>> */
    private function selectTariffsForCurrency(array $tariffs, ?string $currency): array
    {
        if ($currency === null || $tariffs === []) {
            return $tariffs;
        }

        $matching = array_values(array_filter(
            $tariffs,
            static fn (array $tariff): bool => (string) $tariff['currency'] === $currency
        ));
        if ($matching !== []) {
            return $matching;
        }

        $defaults = array_values(array_filter(
            $tariffs,
            static fn (array $tariff): bool => (bool) ($tariff['default'] ?? false)
        ));

        return $defaults !== [] ? $defaults : [$tariffs[0]];
    }

    /** @param array<int,array<string,mixed>> $tariffs */
    private function defaultCurrencyForTariffs(array $tariffs): ?string
    {
        foreach ($tariffs as $tariff) {
            if ((bool) ($tariff['default'] ?? false)) {
                return (string) $tariff['currency'];
            }
        }

        return $tariffs === [] ? null : (string) $tariffs[0]['currency'];
    }
}
