<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class FormRequestLogRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO form_request_log (
                type, email, ip_address, user_agent, page_source, status, spam_score, failure_reason
             ) VALUES (
                :type, :email, :ip_address, :user_agent, :page_source, :status, :spam_score, :failure_reason
             )'
        );
        $stmt->execute([
            'type' => $data['type'],
            'email' => $data['email'] ?? null,
            'ip_address' => $data['ip_address'],
            'user_agent' => $data['user_agent'] ?? null,
            'page_source' => $data['page_source'] ?? null,
            'status' => $data['status'],
            'spam_score' => (int) ($data['spam_score'] ?? 0),
            'failure_reason' => $data['failure_reason'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countRecentByIp(string $type, string $ipAddress, int $minutes): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM form_request_log
             WHERE type = :type
               AND ip_address = :ip_address
               AND created_at >= (UTC_TIMESTAMP() - INTERVAL :minutes MINUTE)'
        );
        $stmt->bindValue('type', $type);
        $stmt->bindValue('ip_address', $ipAddress);
        $stmt->bindValue('minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function countRecentByEmail(string $type, string $email, int $minutes): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM form_request_log
             WHERE type = :type
               AND email = :email
               AND created_at >= (UTC_TIMESTAMP() - INTERVAL :minutes MINUTE)'
        );
        $stmt->bindValue('type', $type);
        $stmt->bindValue('email', mb_strtolower(trim($email)));
        $stmt->bindValue('minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
