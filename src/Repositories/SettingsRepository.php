<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class SettingsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key_name = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();

        return $row ? (string) $row['value'] : null;
    }

    public function set(string $key, ?string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key_name, value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE value = :value2, updated_at = NOW()'
        );
        $stmt->execute(['key' => $key, 'value' => $value, 'value2' => $value]);
    }

    /** @return array<string,string|null> */
    public function getMultiple(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare("SELECT key_name, value FROM settings WHERE key_name IN ($placeholders)");
        $stmt->execute(array_values($keys));

        $result = [];
        foreach ($keys as $k) {
            $result[$k] = null;
        }
        while ($row = $stmt->fetch()) {
            $result[$row['key_name']] = $row['value'];
        }

        return $result;
    }

    /** @param array<string,string|null> $data */
    public function setMultiple(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }
    }
}
