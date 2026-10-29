<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class AppContextRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function findActiveAppByProductSlug(string $productSlug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*
             FROM apps a
             INNER JOIN app_products ap ON ap.app_id = a.id AND ap.is_active = 1
             INNER JOIN products p ON p.id = ap.product_id
             WHERE a.is_active = 1 AND p.slug = :product_slug
             LIMIT 1'
        );
        $stmt->execute(['product_slug' => $productSlug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function appOwnsProductSlug(string $appSlug, ?string $productSlug): bool
    {
        if ($productSlug === null || $productSlug === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM apps a
             INNER JOIN app_products ap ON ap.app_id = a.id AND ap.is_active = 1
             INNER JOIN products p ON p.id = ap.product_id AND p.is_active = 1
             WHERE a.slug = :app_slug AND a.is_active = 1 AND p.slug = :product_slug
             LIMIT 1'
        );
        $stmt->execute([
            'app_slug' => $appSlug,
            'product_slug' => $productSlug,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function resolveAppIdForProductSlug(?string $productSlug): ?int
    {
        if ($productSlug === null || $productSlug === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.id
             FROM apps a
             INNER JOIN app_products ap ON ap.app_id = a.id AND ap.is_active = 1
             INNER JOIN products p ON p.id = ap.product_id
             WHERE a.is_active = 1 AND p.slug = :product_slug
             GROUP BY a.id
             ORDER BY a.id ASC'
        );
        $stmt->execute(['product_slug' => $productSlug]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (count($rows) !== 1) {
            return null;
        }

        return (int) $rows[0];
    }

    public function countActiveAppsForProductSlug(string $productSlug): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT a.id)
             FROM apps a
             INNER JOIN app_products ap ON ap.app_id = a.id AND ap.is_active = 1
             INNER JOIN products p ON p.id = ap.product_id
             WHERE a.is_active = 1 AND p.slug = :product_slug'
        );
        $stmt->execute(['product_slug' => $productSlug]);

        return (int) $stmt->fetchColumn();
    }
}
