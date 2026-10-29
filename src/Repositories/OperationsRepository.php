<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class OperationsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed> */
    public function appOverview(?int $appId = null): array
    {
        $where = '';
        $params = [];
        if ($appId !== null) {
            $where = 'WHERE l.app_id = :app_id';
            $params['app_id'] = $appId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total_licenses,
                SUM(l.status = 'active') AS active_licenses,
                SUM(l.status = 'expired') AS expired_licenses,
                SUM(l.status = 'revoked') AS revoked_licenses,
                COALESCE(SUM(CASE WHEN l.status != 'refunded' THEN l.price ELSE 0 END), 0) AS revenue_total
             FROM licenses l
             {$where}"
        );
        $stmt->execute($params);

        return $stmt->fetch() ?: [];
    }

    /** @return array<string,mixed> */
    public function verificationSummary(?int $appId = null): array
    {
        $where = '';
        $params = [];
        if ($appId !== null) {
            $where = 'WHERE l.app_id = :app_id';
            $params['app_id'] = $appId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(v.status = 'valid') AS valid_total,
                SUM(v.verified_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS last_24h,
                SUM(v.verified_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) AS last_7d,
                SUM(v.verified_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) AS last_30d
             FROM license_verifications v
             INNER JOIN licenses l ON l.id = v.license_id
             {$where}"
        );
        $stmt->execute($params);

        return $stmt->fetch() ?: [];
    }

    /** @return array<string,mixed> */
    public function activationSummary(?int $appId = null): array
    {
        $where = '';
        $params = [];
        if ($appId !== null) {
            $where = 'WHERE l.app_id = :app_id';
            $params['app_id'] = $appId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(a.is_active = 1) AS active_total,
                SUM(a.activated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS last_24h,
                SUM(a.activated_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) AS last_7d
             FROM license_activations a
             INNER JOIN licenses l ON l.id = a.license_id
             {$where}"
        );
        $stmt->execute($params);

        return $stmt->fetch() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function appCards(): array
    {
        return $this->pdo->query(
            "SELECT a.id, a.slug, a.name, a.is_active,
                    COUNT(DISTINCT l.id) AS license_count,
                    COUNT(DISTINCT ap.product_id) AS product_count
             FROM apps a
             LEFT JOIN licenses l ON l.app_id = a.id
             LEFT JOIN app_products ap ON ap.app_id = a.id AND ap.is_active = 1
             GROUP BY a.id
             ORDER BY a.is_active DESC, a.name ASC"
        )->fetchAll() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function recentWebhooks(?int $appId = null, int $limit = 25): array
    {
        $sql = 'SELECT id, event_type, status, error_message, processed_at, payload FROM webhook_logs ORDER BY processed_at DESC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        if ($appId === null) {
            return $rows;
        }

        $app = (new AppRepository())->findById($appId);
        if ($app === null) {
            return [];
        }

        $appSlug = (string) $app['slug'];

        return array_values(array_filter($rows, static function (array $row) use ($appSlug): bool {
            $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
            if (!is_array($payload)) {
                return false;
            }

            $metadata = $payload['metadata'] ?? [];
            return is_array($metadata) && (($metadata['app_slug'] ?? null) === $appSlug);
        }));
    }

    /** @return array<string,int|string|null> */
    public function healthSummary(): array
    {
        return [
            'pending_jobs' => (int) ($this->pdo->query('SELECT COUNT(*) FROM jobs WHERE reserved_at IS NULL')->fetchColumn() ?: 0),
            'reserved_jobs' => (int) ($this->pdo->query('SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL')->fetchColumn() ?: 0),
            'failed_jobs_24h' => (int) ($this->pdo->query('SELECT COUNT(*) FROM failed_jobs WHERE failed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchColumn() ?: 0),
            'webhook_failures_24h' => (int) ($this->pdo->query("SELECT COUNT(*) FROM webhook_logs WHERE status = 'failed' AND processed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn() ?: 0),
            'last_audit_at' => $this->pdo->query('SELECT MAX(created_at) FROM audit_log')->fetchColumn() ?: null,
            'last_health_at' => $this->pdo->query('SELECT MAX(checked_at) FROM system_health_checks')->fetchColumn() ?: null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function recentHealthChecks(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM system_health_checks ORDER BY checked_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
