<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100) UNIQUE NOT NULL,
    value TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key_name (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

try {
    $pdo = \App\Database::pdo();
    $pdo->exec($sql);
    fwrite(STDOUT, "Tabulka 'settings' vytvořena úspěšně.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Chyba: " . $e->getMessage() . "\n");
    exit(1);
}
