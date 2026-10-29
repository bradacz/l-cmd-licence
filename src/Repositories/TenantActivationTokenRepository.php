<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\OpaqueToken;
use PDO;

final class TenantActivationTokenRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function create(
        int $tenantId,
        int $tenantAccessKeyId,
        string $email,
        string $plainToken,
        string $expiresAt,
        ?string $createdIp = null,
        ?string $createdUserAgent = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_activation_tokens (
                tenant_id, tenant_access_key_id, email, token_hash, status, expires_at, created_ip, created_user_agent
             ) VALUES (
                :tenant_id, :tenant_access_key_id, :email, :token_hash, :status, :expires_at, :created_ip, :created_user_agent
             )'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'tenant_access_key_id' => $tenantAccessKeyId,
            'email' => mb_strtolower(trim($email)),
            'token_hash' => OpaqueToken::hash($plainToken),
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'created_ip' => $createdIp,
            'created_user_agent' => $createdUserAgent,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function expirePendingForAccessKey(int $tenantAccessKeyId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE tenant_activation_tokens
             SET status = 'expired'
             WHERE tenant_access_key_id = :tenant_access_key_id
               AND status = 'pending'"
        );
        $stmt->execute(['tenant_access_key_id' => $tenantAccessKeyId]);
    }

    /** @return array<string,mixed>|null */
    public function findValidByToken(string $plainToken, bool $forUpdate = false): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM tenant_activation_tokens
             WHERE token_hash = :token_hash
               AND status = 'pending'
               AND expires_at >= NOW()
             ORDER BY id DESC
             LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([
            'token_hash' => OpaqueToken::hash($plainToken),
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function markUsed(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE tenant_activation_tokens
             SET status = 'used', used_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }
}
