<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class TenantSettingsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,string> */
    public function allForTenant(int $tenantId, bool $forUpdate = false): array
    {
        $stmt = $this->pdo->prepare('SELECT key_name, value FROM tenant_settings WHERE tenant_id = :tenant_id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll() ?: [];

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['key_name']] = (string) ($row['value'] ?? '');
        }

        return $result;
    }

    public function upsert(int $tenantId, string $key, ?string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_settings (tenant_id, key_name, value)
             VALUES (:tenant_id, :key_name, :value)
             ON DUPLICATE KEY UPDATE
             value = VALUES(value),
             updated_at = NOW()'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'key_name' => $key,
            'value' => $value,
        ]);
    }
}
