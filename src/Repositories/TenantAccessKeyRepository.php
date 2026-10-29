<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\OpaqueToken;
use PDO;

final class TenantAccessKeyRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function create(int $tenantId, string $email, string $plainKey, ?string $expiresAt = null, ?int $adminUserId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_access_keys (
                tenant_id, email, access_key_hash, status, expires_at, created_by_admin_user_id
             ) VALUES (
                :tenant_id, :email, :access_key_hash, :status, :expires_at, :created_by_admin_user_id
             )'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'email' => mb_strtolower(trim($email)),
            'access_key_hash' => OpaqueToken::hash($plainKey),
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'created_by_admin_user_id' => $adminUserId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function expirePendingForTenantEmail(int $tenantId, string $email): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE tenant_access_keys
             SET status = 'expired'
             WHERE tenant_id = :tenant_id
               AND email = :email
               AND status = 'pending'"
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'email' => mb_strtolower(trim($email)),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findPendingByEmailAndKey(string $email, string $plainKey): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM tenant_access_keys
             WHERE email = :email
               AND access_key_hash = :access_key_hash
               AND status = 'pending'
               AND (expires_at IS NULL OR expires_at >= NOW())
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([
            'email' => mb_strtolower(trim($email)),
            'access_key_hash' => OpaqueToken::hash($plainKey),
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findPendingById(int $id, bool $forUpdate = false): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM tenant_access_keys
             WHERE id = :id
               AND status = 'pending'
               AND (expires_at IS NULL OR expires_at >= NOW())
             LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function markUsed(int $id, int $tenantUserId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE tenant_access_keys
             SET status = 'used', used_at = NOW(), used_by_tenant_user_id = :tenant_user_id
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'tenant_user_id' => $tenantUserId,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listForTenant(int $tenantId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM tenant_access_keys
             WHERE tenant_id = :tenant_id
             ORDER BY id DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll() ?: [];
    }
}
