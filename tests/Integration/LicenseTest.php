<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/DatabaseTestCase.php';

use App\Services\JwtService;
use App\Services\LicenseService;

final class LicenseTest extends DatabaseTestCase
{
    public function testPendingLicenseCannotActivateImplicitly(): void
    {
        $license = $this->license('pending');
        $result = (new LicenseService())->verify($license['key'], 'device', '127.0.0.1', 'test');
        self::assertSame('invalid', $result['status']);
        $this->assertActivations($license, 0);
    }

    public function testExplicitExpiryAppliesEvenToLifetimeLicense(): void
    {
        $license = $this->license(expires: gmdate('Y-m-d H:i:s', time() - 1));
        $result = (new LicenseService())->verify($license['key'], 'device', '127.0.0.1', 'test');
        self::assertSame('expired', $result['status']);
        $this->assertActivations($license, 0);
    }

    public function testTokenIsBoundToLicenseAndDeviceAndDoesNotOutliveLicense(): void
    {
        $expiry = time() + 3600;
        $license = $this->license(expires: gmdate('Y-m-d H:i:s', $expiry));
        $result = (new LicenseService())->verify($license['key'], 'device', '127.0.0.1', 'test');
        $payload = (new JwtService())->verify($result['token']);
        self::assertSame($expiry, $payload['exp']);
        self::assertSame($license['id'], $payload['license_id']);
        self::assertSame('device', $payload['installation_hash']);
        $this->assertActivations($license, 1);
        $stmt = $this->pdo->prepare('SELECT activated_at FROM licenses WHERE id = ?');
        $stmt->execute([$license['id']]);
        self::assertNotNull($stmt->fetchColumn());
    }

    public function testExplicitEmptyFeaturePolicyDoesNotRestoreDefaults(): void
    {
        $this->policy('enabled_features', []);
        $license = $this->license();
        $result = (new LicenseService())->verify($license['key'], 'device', '127.0.0.1', 'test');
        self::assertSame([], (new JwtService())->verify($result['token'])['features']);
    }

    public function testMissingPlatformCannotBypassRestriction(): void
    {
        $this->policy('allowed_platforms', ['ios']);
        $license = $this->license();
        $service = new LicenseService();
        self::assertSame('invalid', $service->verify($license['key'], 'device', '127.0.0.1', 'test')['status']);
        try {
            $service->activate($license['key'], 'example.invalid', 'device', [], '127.0.0.1', 'test');
            self::fail('Activation must require an allowed platform.');
        } catch (DomainException $e) {
            self::assertSame('Platform not allowed', $e->getMessage());
        }
        $this->assertActivations($license, 0);
        self::assertSame('valid', $service->verify($license['key'], 'device', '127.0.0.1', 'test', ['platform' => 'ios'])['status']);
    }

    public function testFailureAfterActivationRollsBackAllChanges(): void
    {
        $license = $this->license();
        $key = getenv('APP_KEY');
        putenv('APP_KEY=');
        try {
            (new LicenseService())->verify($license['key'], 'device', '127.0.0.1', 'test');
            self::fail('Signing must fail without a valid key.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('APP_KEY', $e->getMessage());
        } finally {
            putenv('APP_KEY=' . $key);
        }
        self::assertFalse($this->pdo->inTransaction());
        $this->assertActivations($license, 0);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM license_verifications WHERE license_id = ?');
        $stmt->execute([$license['id']]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testConcurrentVerificationRespectsSingleDeviceLimit(): void
    {
        $license = $this->license();
        $statuses = $this->concurrent($license, ['a', 'b', 'c', 'd'], 'verify');
        self::assertSame(1, count(array_filter($statuses, static fn ($s) => $s === 'valid')));
        self::assertSame(3, count(array_filter($statuses, static fn ($s) => $s === 'limit_exceeded')));
        $this->assertActivations($license, 1);
    }

    public function testConcurrentExplicitActivationRespectsLimit(): void
    {
        $license = $this->license();
        $statuses = $this->concurrent($license, ['a', 'b', 'c', 'd'], 'activate');
        self::assertSame(1, count(array_filter($statuses, static fn ($s) => $s === 'success')));
        $this->assertActivations($license, 1);
    }

    public function testConcurrentVerificationOfSameDeviceCreatesOneActivation(): void
    {
        $license = $this->license();
        self::assertSame(['valid', 'valid', 'valid', 'valid'], $this->concurrent($license, ['a', 'a', 'a', 'a'], 'verify'));
        $this->assertActivations($license, 1);
    }

    public function testConcurrentRepeatedDeactivationDoesNotFreeAnotherDeviceSlot(): void
    {
        $license = $this->license(limit: 2);
        $service = new LicenseService();
        $service->verify($license['key'], 'a', '127.0.0.1', 'test');
        $service->verify($license['key'], 'b', '127.0.0.1', 'test');
        $this->concurrent($license, ['a', 'a', 'a', 'a'], 'deactivate');
        $service->deactivate($license['key'], 'a', 'test');
        $this->assertActivations($license, 1);
        self::assertSame('valid', $service->verify($license['key'], 'c', '127.0.0.1', 'test')['status']);
        self::assertSame('limit_exceeded', $service->verify($license['key'], 'd', '127.0.0.1', 'test')['status']);
    }

    private function concurrent(array $license, array $devices, string $operation): array
    {
        $workers = [];
        $statuses = [];
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare('SELECT id FROM licenses WHERE id = ? FOR UPDATE');
        $stmt->execute([$license['id']]);
        try {
            foreach ($devices as $device) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/license-worker.php', $operation, $license['key'], $device],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 15);
                self::assertSame("ready\n", fgets($pipes[1]));
            }
            // Every worker is ready before the shared parent-row lock is released.
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
                fflush($pipes[0]);
            }
            $this->pdo->commit();
            foreach ($workers as [, $pipes]) {
                $line = fgets($pipes[1]);
                self::assertIsString($line);
                $statuses[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR)['status'];
            }
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[0]);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                self::assertSame('', $errors);
            }
        }
        return $statuses;
    }
}
