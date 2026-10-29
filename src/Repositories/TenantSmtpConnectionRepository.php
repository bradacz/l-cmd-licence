<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\SecretBox;
use PDO;

final class TenantSmtpConnectionRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function findByTenantId(int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenant_smtp_connections WHERE tenant_id = :tenant_id LIMIT 1');
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $passwordEncrypted = $row['password_encrypted'] ?? null;
        $password = SecretBox::decrypt($passwordEncrypted) ?? '';

        if (SecretBox::needsReencrypt(is_string($passwordEncrypted) ? $passwordEncrypted : null) && $password !== '') {
            $update = $this->pdo->prepare('UPDATE tenant_smtp_connections
                SET password_encrypted = :password
                WHERE tenant_id = :tenant_id AND password_encrypted = :old_password');
            $update->execute([
                'password' => SecretBox::encrypt($password),
                'tenant_id' => $tenantId,
                'old_password' => $passwordEncrypted,
            ]);
        }

        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
            'host' => (string) ($row['host'] ?? ''),
            'port' => (int) ($row['port'] ?? 587),
            'username' => (string) ($row['username'] ?? ''),
            'password' => $password,
            'encryption' => (string) ($row['encryption'] ?? 'tls'),
            'from_email' => (string) ($row['from_email'] ?? ''),
            'from_name' => (string) ($row['from_name'] ?? ''),
        ];
    }

    public function upsert(int $tenantId, array $fields): void
    {
        $current = $this->findByTenantId($tenantId) ?? [];
        $password = array_key_exists('password', $fields) ? (string) $fields['password'] : (string) ($current['password'] ?? '');

        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_smtp_connections (tenant_id, is_enabled, host, port, username, password_encrypted, encryption, from_email, from_name)
             VALUES (:tenant_id, :is_enabled, :host, :port, :username, :password_encrypted, :encryption, :from_email, :from_name)
             ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                host = VALUES(host),
                port = VALUES(port),
                username = VALUES(username),
                password_encrypted = VALUES(password_encrypted),
                encryption = VALUES(encryption),
                from_email = VALUES(from_email),
                from_name = VALUES(from_name),
                updated_at = NOW()'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'is_enabled' => array_key_exists('is_enabled', $fields) ? ((bool) $fields['is_enabled'] ? 1 : 0) : ((int) (($current['is_enabled'] ?? true) ? 1 : 0)),
            'host' => trim((string) ($fields['host'] ?? ($current['host'] ?? ''))) ?: null,
            'port' => (int) ($fields['port'] ?? ($current['port'] ?? 587)),
            'username' => trim((string) ($fields['username'] ?? ($current['username'] ?? ''))) ?: null,
            'password_encrypted' => SecretBox::encrypt($password),
            'encryption' => trim((string) ($fields['encryption'] ?? ($current['encryption'] ?? 'tls'))) ?: 'tls',
            'from_email' => trim((string) ($fields['from_email'] ?? ($current['from_email'] ?? ''))) ?: null,
            'from_name' => trim((string) ($fields['from_name'] ?? ($current['from_name'] ?? ''))) ?: null,
        ]);
    }
}
