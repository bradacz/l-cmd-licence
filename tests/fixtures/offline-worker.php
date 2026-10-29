<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');
if (getenv('LCMD_TEST_DB') !== '1' || !preg_match('/^[a-zA-Z0-9_]+_test$/D', (string) getenv('DB_DATABASE'))) {
    exit(2);
}
$keys = (new App\Services\OfflineTokenSigner())->publicKeyData((int) $argv[1]);
fwrite(STDOUT, $keys['key_id'] . "\n");
