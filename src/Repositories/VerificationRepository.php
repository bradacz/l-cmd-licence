<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class VerificationRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function log(int $licenseId, ?int $activationId, string $status, ?string $ip = null, ?string $userAgent = null, ?int $responseMs = null, ?string $error = null): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO license_verifications
            (license_id, activation_id, ip_address, user_agent, status, response_time_ms, error_message)
            VALUES (:license_id, :activation_id, :ip_address, :user_agent, :status, :response_time_ms, :error_message)');
        $stmt->execute([
            'license_id' => $licenseId,
            'activation_id' => $activationId,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => $status,
            'response_time_ms' => $responseMs,
            'error_message' => $error,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listForLicense(int $licenseId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, activation_id, verified_at, ip_address, user_agent, status, response_time_ms, error_message
             FROM license_verifications
             WHERE license_id = :license_id
             ORDER BY verified_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':license_id', $licenseId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
