<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class WebhookReceiptRepository
{
    public function runOnce(string $scope, string $eventId, callable $work): bool
    {
        $pdo = Database::pdo();
        // Serialize one account's events without nesting handlers' own transactions.
        $lock = 'stripe:' . substr(hash('sha256', $scope), 0, 48);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$lock]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \RuntimeException('Webhook processing is busy; retry later.');
        }
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM webhook_receipts WHERE scope = ? AND event_id = ?');
            $stmt->execute([$scope, $eventId]);
            if ($stmt->fetchColumn() !== false) {
                return false;
            }
            $work();
            $pdo->prepare('INSERT INTO webhook_receipts (scope, event_id) VALUES (?, ?)')->execute([$scope, $eventId]);
            return true;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
}
