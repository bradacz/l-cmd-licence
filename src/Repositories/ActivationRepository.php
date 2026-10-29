<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class ActivationRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function findActiveByInstallationHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM license_activations WHERE installation_hash = :hash AND is_active = 1');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findActiveForLicense(int $licenseId, string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM license_activations WHERE license_id = :license_id AND installation_hash = :hash AND is_active = 1');
        $stmt->execute(['license_id' => $licenseId, 'hash' => $hash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(int $licenseId, array $payload): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO license_activations
            (license_id, domain, server_ip, installation_hash, php_version, cms_version, database_type, is_active, last_ping_at)
            VALUES (:license_id, :domain, :server_ip, :installation_hash, :php_version, :cms_version, :database_type, 1, NOW())');
        $stmt->execute([
            'license_id' => $licenseId,
            'domain' => $payload['domain'],
            'server_ip' => $payload['server_ip'],
            'installation_hash' => $payload['installation_hash'],
            'php_version' => $payload['php_version'] ?? null,
            'cms_version' => $payload['cms_version'] ?? null,
            'database_type' => $payload['database_type'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function touchPing(int $activationId): void
    {
        $stmt = $this->pdo->prepare('UPDATE license_activations SET last_ping_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $activationId]);
    }

    public function deactivate(int $activationId, string $reason): void
    {
        $stmt = $this->pdo->prepare('UPDATE license_activations SET is_active = 0, deactivated_at = NOW(), deactivation_reason = :reason WHERE id = :id');
        $stmt->execute(['id' => $activationId, 'reason' => $reason]);
    }

    /** @return array<string,mixed>|null */
    public function findForDeactivation(string $licenseKey, string $installationHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT a.* FROM license_activations a
            INNER JOIN licenses l ON l.id = a.license_id
            WHERE l.license_key = :license_key AND a.installation_hash = :installation_hash AND a.is_active = 1');
        $stmt->execute([
            'license_key' => $licenseKey,
            'installation_hash' => $installationHash,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listForLicense(int $licenseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, domain, server_ip, installation_hash, php_version, cms_version, database_type,
                    is_active, activated_at, last_ping_at, deactivated_at, deactivation_reason
             FROM license_activations
             WHERE license_id = :license_id
             ORDER BY activated_at DESC'
        );
        $stmt->execute(['license_id' => $licenseId]);

        return $stmt->fetchAll() ?: [];
    }
}
