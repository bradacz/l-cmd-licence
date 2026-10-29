<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$schemaPath = dirname(__DIR__) . '/database/schema.sql';
if (!is_file($schemaPath)) {
    fwrite(STDERR, "Schema file not found: {$schemaPath}\n");
    exit(1);
}

$sql = file_get_contents($schemaPath);
if ($sql === false) {
    fwrite(STDERR, "Cannot read schema.sql\n");
    exit(1);
}

$pdo = \App\Database::pdo();
$pdo->exec($sql);

fwrite(STDOUT, "Schema imported successfully.\n");
