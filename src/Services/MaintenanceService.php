<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

final class MaintenanceService
{
    public function deactivateInactiveActivations(int $days = 30): int
    {
        $pdo = Database::pdo();

        $select = $pdo->prepare('SELECT id, license_id FROM license_activations
            WHERE is_active = 1 AND (last_ping_at IS NULL OR last_ping_at < DATE_SUB(NOW(), INTERVAL :days DAY))');
        $select->bindValue(':days', $days, \PDO::PARAM_INT);
        $select->execute();
        $rows = $select->fetchAll() ?: [];

        $count = 0;
        foreach ($rows as $row) {
            $upd = $pdo->prepare('UPDATE license_activations SET is_active = 0, deactivated_at = NOW(), deactivation_reason = :reason WHERE id = :id');
            $upd->execute(['reason' => 'Inactive for 30+ days', 'id' => $row['id']]);

            $dec = $pdo->prepare('UPDATE licenses SET current_activations = GREATEST(current_activations - 1, 0) WHERE id = :id');
            $dec->execute(['id' => $row['license_id']]);
            $count++;
        }

        return $count;
    }

    public function pruneRateLimitRows(int $hours = 48): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('DELETE FROM api_rate_limits WHERE updated_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)');
        $stmt->bindValue(':hours', $hours, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function pruneOldVerifications(int $days = 90): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('DELETE FROM license_verifications WHERE verified_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function checkExpiringLicenses(int $daysBeforeExpiry = 7): int
    {
        $pdo = Database::pdo();

        // Licence expirující za X dní - odeslat upozornění
        $stmt = $pdo->query(
            "SELECT id FROM licenses
             WHERE status = 'active'
             AND license_type != 'lifetime'
             AND expires_at IS NOT NULL
             AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL {$daysBeforeExpiry} DAY)"
        );
        $expiring = $stmt->fetchAll() ?: [];

        $mail = new MailService();
        $count = 0;
        foreach ($expiring as $row) {
            if ($mail->sendExpiringSoon((int) $row['id'])) {
                $count++;
            }
        }

        return $count;
    }

    public function expireLicenses(): int
    {
        $pdo = Database::pdo();

        // Najdi licence, které právě vypršely
        $stmt = $pdo->query(
            "SELECT id FROM licenses
             WHERE status = 'active'
             AND license_type != 'lifetime'
             AND expires_at IS NOT NULL
             AND expires_at < NOW()"
        );
        $expired = $stmt->fetchAll() ?: [];

        $mail = new MailService();
        $count = 0;
        foreach ($expired as $row) {
            $pdo->prepare("UPDATE licenses SET status = 'expired' WHERE id = :id")
                ->execute(['id' => $row['id']]);
            $mail->sendExpired((int) $row['id']);
            $count++;
        }

        return $count;
    }
}
