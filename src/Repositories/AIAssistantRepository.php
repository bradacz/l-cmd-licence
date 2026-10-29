<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Services\TenantContextService;
use PDO;

final class AIAssistantRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function create(array $data): int
    {
        $tenantContext = new TenantContextService();
        $tenantId = isset($data['tenant_id'])
            ? (int) $data['tenant_id']
            : (isset($data['app_id']) && $data['app_id'] !== null
                ? $tenantContext->tenantIdForAppId((int) $data['app_id'])
                : $tenantContext->defaultTenantId());

        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_assistant_runs
             (tenant_id, app_id, triggered_by, task_type, input_text, output_text, status)
             VALUES (:tenant_id, :app_id, :triggered_by, :task_type, :input_text, :output_text, :status)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'app_id' => $data['app_id'] ?? null,
            'triggered_by' => $data['triggered_by'] ?? null,
            'task_type' => $data['task_type'],
            'input_text' => $data['input_text'] ?? null,
            'output_text' => $data['output_text'] ?? null,
            'status' => $data['status'] ?? 'completed',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(?int $appId = null, int $limit = 20): array
    {
        $tenantId = (new TenantContextService())->defaultTenantId();
        return $this->recentForTenant($tenantId, $appId, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function recentForTenant(int $tenantId, ?int $appId = null, int $limit = 20): array
    {
        $where = '';
        $params = ['tenant_id' => $tenantId];
        $where = 'WHERE r.tenant_id = :tenant_id';
        if ($appId !== null) {
            $where .= ' AND r.app_id = :app_id';
            $params['app_id'] = $appId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT r.*, a.name AS app_name, a.slug AS app_slug
             FROM ai_assistant_runs r
             LEFT JOIN apps a ON a.id = r.app_id
             {$where}
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT :limit"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
