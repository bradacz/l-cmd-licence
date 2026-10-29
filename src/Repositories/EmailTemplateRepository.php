<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class EmailTemplateRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query('SELECT * FROM email_templates ORDER BY template_key ASC')->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $templateKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_templates WHERE template_key = :template_key LIMIT 1');
        $stmt->execute(['template_key' => $templateKey]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
