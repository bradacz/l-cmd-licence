<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Services\LicenseKeyGenerator;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/create_license.php <type> <customer_email> <customer_name> [price] [currency] [max_activations]\n");
    exit(1);
}

$type = $argv[1];
$email = $argv[2];
$name = $argv[3];
$price = isset($argv[4]) ? (float) $argv[4] : null;
$currency = $argv[5] ?? 'CZK';
$maxActivations = isset($argv[6]) ? (int) $argv[6] : ($type === 'project' ? 1 : 999);

$generator = new LicenseKeyGenerator();
$key = $generator->generate($type);
$expiresAt = $type === 'annual' ? date('Y-m-d H:i:s', time() + 31536000) : null;

$pdo = \App\Database::pdo();
$stmt = $pdo->prepare('INSERT INTO licenses
(license_key, license_type, customer_email, customer_name, price, currency, status, max_activations, expires_at)
VALUES
(:license_key, :license_type, :customer_email, :customer_name, :price, :currency, :status, :max_activations, :expires_at)');
$stmt->execute([
    'license_key' => $key,
    'license_type' => $type,
    'customer_email' => $email,
    'customer_name' => $name,
    'price' => $price,
    'currency' => $currency,
    'status' => 'active',
    'max_activations' => $maxActivations,
    'expires_at' => $expiresAt,
]);

fwrite(STDOUT, "License created: {$key}\n");
