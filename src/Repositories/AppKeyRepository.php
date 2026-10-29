<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Services\TenantContextService;
use PDO;

final class AppKeyRepository
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
            'SELECT * FROM app_keys WHERE app_id = :app_id ORDER BY is_active DESC, created_at DESC, id DESC'
        );
        $stmt->execute(['app_id' => $appId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function findByIdForApp(int $id, int $appId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM app_keys WHERE id = :id AND app_id = :app_id LIMIT 1');
        $stmt->execute([
            'id' => $id,
            'app_id' => $appId,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $tenantId = (int) ($data['tenant_id'] ?? (new TenantContextService())->tenantIdForAppId((int) $data['app_id']));

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_keys (tenant_id, app_id, key_name, key_type, key_id, public_key, secret_hash, last4, is_active, rotated_at, expires_at)
             VALUES (:tenant_id, :app_id, :key_name, :key_type, :key_id, :public_key, :secret_hash, :last4, :is_active, :rotated_at, :expires_at)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'app_id' => $data['app_id'],
            'key_name' => $data['key_name'],
            'key_type' => $data['key_type'],
            'key_id' => $data['key_id'],
            'public_key' => $data['public_key'] ?? null,
            'secret_hash' => $data['secret_hash'] ?? null,
            'last4' => $data['last4'] ?? null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'rotated_at' => $data['rotated_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE app_keys SET is_active = 0, rotated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
