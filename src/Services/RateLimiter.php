<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Support\Env;

final class RateLimiter
{
    public function allow(string $ip, string $endpoint, ?int $limit = null): bool
    {
        $pdo = Database::pdo();
        $bucket = date('YmdHi');
        $effectiveLimit = $limit ?? Env::int('RATE_LIMIT_PER_MINUTE', 100);

        $stmt = $pdo->prepare('INSERT INTO api_rate_limits (ip_address, endpoint, minute_bucket, request_count)
            VALUES (:ip, :endpoint, :bucket, 1)
            ON DUPLICATE KEY UPDATE request_count = request_count + 1');
        $stmt->execute(['ip' => $ip, 'endpoint' => $endpoint, 'bucket' => $bucket]);

        $sel = $pdo->prepare('SELECT request_count FROM api_rate_limits WHERE ip_address = :ip AND endpoint = :endpoint AND minute_bucket = :bucket');
        $sel->execute(['ip' => $ip, 'endpoint' => $endpoint, 'bucket' => $bucket]);
        $count = (int) ($sel->fetchColumn() ?: 0);

        return $count <= $effectiveLimit;
    }
}
