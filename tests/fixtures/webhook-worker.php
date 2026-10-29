<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');
if (getenv('LCMD_TEST_DB') !== '1' || !preg_match('/^[a-zA-Z0-9_]+_test$/D', (string) getenv('DB_DATABASE'))) {
    exit(2);
}
try {
    $input = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $result = (new App\Services\StripeWebhookService())->handle($input['payload'], $input['signature']);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
