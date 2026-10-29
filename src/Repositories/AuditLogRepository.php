<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Json;
use PDO;

final class AuditLogRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function log(?int $adminUserId, ?int $licenseId, string $action, string $entityType, ?int $entityId, array $oldValues, array $newValues, string $ip, string $userAgent): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO audit_log
            (admin_user_id, license_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
            VALUES (:admin_user_id, :license_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent)');

        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'license_id' => $licenseId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => Json::encode($oldValues),
            'new_values' => Json::encode($newValues),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }
}
