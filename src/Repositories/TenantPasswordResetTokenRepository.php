<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\OpaqueToken;
use PDO;

final class TenantPasswordResetTokenRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function create(int $tenantUserId, string $plainToken, string $expiresAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_password_reset_tokens (
                tenant_user_id, token_hash, expires_at
             ) VALUES (
                :tenant_user_id, :token_hash, :expires_at
             )'
        );
        $stmt->execute([
            'tenant_user_id' => $tenantUserId,
            'token_hash' => OpaqueToken::hash($plainToken),
            'expires_at' => $expiresAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findValidByToken(string $plainToken, bool $forUpdate = false): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM tenant_password_reset_tokens
             WHERE token_hash = :token_hash
               AND used_at IS NULL
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
            'UPDATE tenant_password_reset_tokens SET used_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }
}
