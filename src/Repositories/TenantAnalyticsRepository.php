<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class TenantAnalyticsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed> */
    public function basicSummary(int $tenantId): array
    {
        $licenseCounts = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total_licenses,
                SUM(status = 'active') AS active_licenses,
                SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS issued_last_30_days
             FROM licenses
             WHERE tenant_id = :tenant_id"
        );
        $licenseCounts->execute(['tenant_id' => $tenantId]);

        $verifyCounts = $this->pdo->prepare(
            "SELECT COUNT(*) AS verifications_last_24h
             FROM license_verifications v
             INNER JOIN licenses l ON l.id = v.license_id
             WHERE l.tenant_id = :tenant_id
               AND v.verified_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $verifyCounts->execute(['tenant_id' => $tenantId]);

        $activationCounts = $this->pdo->prepare(
            "SELECT COUNT(*) AS activations_last_24h
             FROM license_activations a
             INNER JOIN licenses l ON l.id = a.license_id
             WHERE l.tenant_id = :tenant_id
               AND a.activated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $activationCounts->execute(['tenant_id' => $tenantId]);

        $appBreakdown = $this->pdo->prepare(
            "SELECT a.id, a.slug, a.name,
                    COUNT(l.id) AS total_licenses,
                    SUM(l.status = 'active') AS active_licenses
             FROM apps a
             LEFT JOIN licenses l ON l.app_id = a.id
             WHERE a.tenant_id = :tenant_id
             GROUP BY a.id
             ORDER BY active_licenses DESC, total_licenses DESC, a.name ASC"
        );
        $appBreakdown->execute(['tenant_id' => $tenantId]);

        $appSlugsStmt = $this->pdo->prepare('SELECT slug FROM apps WHERE tenant_id = :tenant_id');
        $appSlugsStmt->execute(['tenant_id' => $tenantId]);
        $appSlugs = array_values(array_filter(array_map('strval', $appSlugsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        $webhookStats = ['success_last_24h' => 0, 'failed_last_24h' => 0];
        if ($appSlugs !== []) {
            $rows = (new WebhookLogRepository())->listForTenantApps($appSlugs, 250);
            $cutoff = strtotime('-24 hours');
            foreach ($rows as $row) {
                $processedAt = strtotime((string) ($row['processed_at'] ?? ''));
                if ($processedAt === false || $processedAt < $cutoff) {
                    continue;
                }
                if ((string) ($row['status'] ?? '') === 'success') {
                    $webhookStats['success_last_24h']++;
                } else {
                    $webhookStats['failed_last_24h']++;
                }
            }
        }

        return [
            'overview' => array_merge(
                $licenseCounts->fetch() ?: [],
                $verifyCounts->fetch() ?: [],
                $activationCounts->fetch() ?: [],
                $webhookStats
            ),
            'apps' => $appBreakdown->fetchAll() ?: [],
        ];
    }

    /** @return array<string,mixed> */
    public function advancedSummary(int $tenantId): array
    {
        $basic = $this->basicSummary($tenantId);

        $issuedSeries = $this->dailySeries(
            'SELECT DATE(created_at) AS bucket, COUNT(*) AS value
             FROM licenses
             WHERE tenant_id = :tenant_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(created_at)'
        , $tenantId);

        $verificationSeries = $this->dailySeries(
            'SELECT DATE(v.verified_at) AS bucket, COUNT(*) AS value
             FROM license_verifications v
             INNER JOIN licenses l ON l.id = v.license_id
             WHERE l.tenant_id = :tenant_id AND v.verified_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(v.verified_at)'
        , $tenantId);

        $activationSeries = $this->dailySeries(
            'SELECT DATE(a.activated_at) AS bucket, COUNT(*) AS value
             FROM license_activations a
             INNER JOIN licenses l ON l.id = a.license_id
             WHERE l.tenant_id = :tenant_id AND a.activated_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(a.activated_at)'
        , $tenantId);

        return array_merge($basic, [
            'series' => [
                'issued_30d' => $issuedSeries,
                'verifications_30d' => $verificationSeries,
                'activations_30d' => $activationSeries,
            ],
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function dailySeries(string $sql, int $tenantId): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $raw = $stmt->fetchAll() ?: [];
        $map = [];
        foreach ($raw as $row) {
            $map[(string) $row['bucket']] = (int) ($row['value'] ?? 0);
        }

        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime('-' . $i . ' days'));
            $series[] = [
                'date' => $date,
                'value' => $map[$date] ?? 0,
            ];
        }

        return $series;
    }
}
