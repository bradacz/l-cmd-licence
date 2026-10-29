<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$pdo = \App\Database::pdo();

$stmt = $pdo->query('SELECT * FROM jobs WHERE reserved_at IS NULL AND available_at <= NOW() ORDER BY id ASC LIMIT 10');
$jobs = $stmt->fetchAll() ?: [];

foreach ($jobs as $job) {
    $reserve = $pdo->prepare('UPDATE jobs SET reserved_at = NOW(), attempts = attempts + 1 WHERE id = :id AND reserved_at IS NULL');
    $reserve->execute(['id' => $job['id']]);

    if ($reserve->rowCount() !== 1) {
        continue;
    }

    try {
        // Placeholder worker; implement specific handlers by job type.
        $delete = $pdo->prepare('DELETE FROM jobs WHERE id = :id');
        $delete->execute(['id' => $job['id']]);
    } catch (Throwable $e) {
        $fail = $pdo->prepare('INSERT INTO failed_jobs (job_id, error_message, payload) VALUES (:job_id, :error_message, :payload)');
        $fail->execute([
            'job_id' => $job['id'],
            'error_message' => $e->getMessage(),
            'payload' => $job['payload'],
        ]);
    }
}
