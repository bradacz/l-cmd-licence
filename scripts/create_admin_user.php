<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/create_admin_user.php <email> <name> <password> [role]\n");
    exit(1);
}

$email = $argv[1];
$name = $argv[2];
$password = $argv[3];
$role = $argv[4] ?? 'admin';

$pdo = \App\Database::pdo();
$hash = password_hash($password, PASSWORD_BCRYPT);

$username = strtolower(explode('@', $email)[0]);

$stmt = $pdo->prepare('INSERT INTO admin_users (username, email, password_hash, name, role) VALUES (:username, :email, :password_hash, :name, :role)
    ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), name = VALUES(name), role = VALUES(role)');
$stmt->execute([
    'username' => $username,
    'email' => $email,
    'password_hash' => $hash,
    'name' => $name,
    'role' => $role,
]);

fwrite(STDOUT, "Admin user upserted: {$email}\n");
