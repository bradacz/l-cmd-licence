<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Json;
use PDO;

final class WebhookLogRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function log(string $eventType, array $payload, ?string $signature, string $status = 'success', ?string $error = null): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO webhook_logs (event_type, payload, signature, status, error_message)
            VALUES (:event_type, :payload, :signature, :status, :error_message)');
        $stmt->execute([
            'event_type' => $eventType,
            'payload' => Json::encode($payload),
            'signature' => $signature,
            'status' => $status,
            'error_message' => $error,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listForTenantApps(array $appSlugs, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, event_type, status, error_message, processed_at, payload
             FROM webhook_logs
             ORDER BY processed_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($appSlugs === []) {
            return [];
        }

        return array_values(array_filter($rows, static function (array $row) use ($appSlugs): bool {
            $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
            if (!is_array($payload)) {
                return false;
            }

            $metadata = $payload['metadata'] ?? [];
            return is_array($metadata) && in_array((string) ($metadata['app_slug'] ?? ''), $appSlugs, true);
        }));
    }
}
