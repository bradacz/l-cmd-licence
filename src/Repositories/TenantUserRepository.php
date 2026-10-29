<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class TenantUserRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenant_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenant_users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => mb_strtolower(trim($email))]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(string $email, string $passwordHash, ?string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_users (email, password_hash, name, is_active, email_verified_at)
             VALUES (:email, :password_hash, :name, 1, NOW())'
        );
        $stmt->execute([
            'email' => mb_strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'name' => $name,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateLastLoginAt(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE tenant_users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function updatePassword(int $id, string $passwordHash, ?string $name = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tenant_users
             SET password_hash = :password_hash,
                 name = COALESCE(:name, name),
                 email_verified_at = COALESCE(email_verified_at, NOW())
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'password_hash' => $passwordHash,
            'name' => $name,
        ]);
    }
}
