<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class LicenseRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $licenseKey, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT l.*, a.slug AS app_slug, a.name AS app_name
                FROM licenses l
                LEFT JOIN apps a ON a.id = l.app_id
                WHERE l.license_key = :license_key';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['license_key' => $licenseKey]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function incrementActivations(int $licenseId): void
    {
        $stmt = $this->pdo->prepare('UPDATE licenses SET current_activations = current_activations + 1 WHERE id = :id');
        $stmt->execute(['id' => $licenseId]);
    }

    public function decrementActivations(int $licenseId): void
    {
        $stmt = $this->pdo->prepare('UPDATE licenses SET current_activations = GREATEST(current_activations - 1, 0) WHERE id = :id');
        $stmt->execute(['id' => $licenseId]);
    }

    public function setActivatedAtIfEmpty(int $licenseId): void
    {
        $stmt = $this->pdo->prepare('UPDATE licenses SET activated_at = COALESCE(activated_at, NOW()) WHERE id = :id');
        $stmt->execute(['id' => $licenseId]);
    }

    public function touchVerified(int $licenseId): void
    {
        $stmt = $this->pdo->prepare('UPDATE licenses SET last_verified_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $licenseId]);
    }

    public function countActiveForTenant(int $tenantId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM licenses WHERE tenant_id = :tenant_id AND status = 'active'");
        $stmt->execute(['tenant_id' => $tenantId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function setExpired(int $licenseId): void
    {
        $stmt = $this->pdo->prepare("UPDATE licenses SET status = 'expired' WHERE id = :id");
        $stmt->execute(['id' => $licenseId]);
    }

    public function updateByKey(string $licenseKey, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $sets = [];
        $params = ['license_key' => $licenseKey];
        foreach ($fields as $key => $value) {
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $sql = 'UPDATE licenses SET ' . implode(', ', $sets) . ' WHERE license_key = :license_key';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /** @return array<string,mixed> */
    public function adminStats(): array
    {
        $overview = $this->pdo->query("SELECT
            COUNT(*) AS total_licenses,
            SUM(status='active') AS active_licenses,
            SUM(status='expired') AS expired_licenses,
            SUM(status='revoked') AS revoked_licenses
            FROM licenses")->fetch() ?: [];

        $revenue = $this->pdo->query("SELECT
            COALESCE(SUM(CASE WHEN YEAR(issued_at)=YEAR(CURDATE()) AND MONTH(issued_at)=MONTH(CURDATE()) THEN price ELSE 0 END), 0) AS this_month,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(issued_at, '%Y-%m')=DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m') THEN price ELSE 0 END), 0) AS last_month,
            'CZK' AS currency,
            COALESCE(SUM(CASE WHEN status='active' AND license_type='annual' THEN price ELSE 0 END), 0) AS arr
            FROM licenses WHERE status != 'refunded'")->fetch() ?: [];

        $activations = $this->pdo->query("SELECT
            COUNT(*) AS total,
            SUM(activated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS last_24h
            FROM license_activations")->fetch() ?: [];

        return [
            'overview' => array_merge(['status' => 'healthy'], $overview),
            'revenue' => $revenue,
            'activations' => $activations,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function listForAdmin(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'license_type = :type';
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = "MATCH(customer_name, customer_email, customer_company) AGAINST(:search IN NATURAL LANGUAGE MODE)";
            $params['search'] = $filters['search'];
        }

        if (!empty($filters['app_id'])) {
            $where[] = 'l.app_id = :app_id';
            $params['app_id'] = (int) $filters['app_id'];
        }

        $sql = 'SELECT l.id, l.app_id, a.slug AS app_slug, a.name AS app_name, l.license_key, l.license_type, l.customer_email, l.customer_name, l.status, l.activated_at, l.current_activations, l.max_activations, l.created_at
                FROM licenses l
                LEFT JOIN apps a ON a.id = l.app_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', (int) ($filters['limit'] ?? 50), PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) ($filters['offset'] ?? 0), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function detailForAdmin(string $licenseKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, a.slug AS app_slug, a.name AS app_name
             FROM licenses l
             LEFT JOIN apps a ON a.id = l.app_id
             WHERE l.license_key = :key'
        );
        $stmt->execute(['key' => $licenseKey]);
        $license = $stmt->fetch();
        if (!$license) {
            return null;
        }

        $actStmt = $this->pdo->prepare('SELECT domain, activated_at, last_ping_at, php_version, cms_version FROM license_activations WHERE license_id = :id ORDER BY activated_at DESC');
        $actStmt->execute(['id' => $license['id']]);
        $activations = $actStmt->fetchAll() ?: [];

        $verStmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM license_verifications WHERE license_id = :id AND verified_at > DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $verStmt->execute(['id' => $license['id']]);
        $ver = $verStmt->fetch();

        return [
            'license' => $license,
            'activations' => $activations,
            'verifications_last_30_days' => (int) ($ver['cnt'] ?? 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function listForTenant(int $tenantId, array $filters = []): array
    {
        $where = ['l.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['app_id'])) {
            $where[] = 'l.app_id = :app_id';
            $params['app_id'] = (int) $filters['app_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'l.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(l.license_key LIKE :search OR l.customer_email LIKE :search OR l.customer_name LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $sql = 'SELECT l.id, l.app_id, a.slug AS app_slug, a.name AS app_name, l.license_key, l.license_type, l.product_slug, l.product_name,
                       l.customer_email, l.customer_name, l.status, l.price, l.currency, l.expires_at, l.current_activations, l.max_activations,
                       l.created_at, l.last_verified_at
                FROM licenses l
                LEFT JOIN apps a ON a.id = l.app_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY l.created_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', (int) ($filters['limit'] ?? 50), PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) ($filters['offset'] ?? 0), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function detailForTenant(int $tenantId, string $licenseKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, a.slug AS app_slug, a.name AS app_name
             FROM licenses l
             LEFT JOIN apps a ON a.id = l.app_id
             WHERE l.tenant_id = :tenant_id AND l.license_key = :license_key
             LIMIT 1'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'license_key' => $licenseKey,
        ]);
        $license = $stmt->fetch();

        return $license ?: null;
    }
}
