<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

if (getenv('LCMD_TEST_DB') !== '1' || !preg_match('/^[a-zA-Z0-9_]+_test$/D', (string) getenv('DB_DATABASE'))) {
    exit(2);
}

try {
    $service = new App\Services\LicenseService();
    fwrite(STDOUT, "ready\n");
    if (trim((string) fgets(STDIN)) !== 'go') {
        exit(3);
    }
    if ($argv[1] === 'deactivate') {
        $service->deactivate($argv[2], $argv[3], 'Regression test');
        $result = ['status' => 'deactivated'];
    } elseif ($argv[1] === 'activate') {
        try {
            $result = $service->activate($argv[2], 'example.invalid', $argv[3], [], '127.0.0.1', 'Regression test');
        } catch (DomainException $e) {
            $result = ['status' => 'rejected'];
        }
    } else {
        $result = $service->verify($argv[2], $argv[3], '127.0.0.1', 'Regression test');
    }
    fwrite(STDOUT, json_encode(['status' => $result['status']], JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
