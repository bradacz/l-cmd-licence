<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/DatabaseTestCase.php';

use App\Repositories\TenantSettingsRepository;
use App\Services\OfflineTokenSigner;

final class OfflineSignerTest extends DatabaseTestCase
{
    public function testSignatureVerifiesWithPublishedKeyAndKeysRemainStable(): void
    {
        $signer = new OfflineTokenSigner();
        $keys = $signer->publicKeyData($this->tenantId);
        $token = $signer->issue($this->tenantId, ['exp' => time() + 300, 'license_id' => 1]);
        [$header, $payload, $signature] = explode('.', $token);
        self::assertSame(1, openssl_verify($header . '.' . $payload, base64_decode(strtr($signature, '-_', '+/')), $keys['public_key_pem'], OPENSSL_ALGO_SHA256));
        self::assertSame($keys, $signer->publicKeyData($this->tenantId));
    }

    public function testUnreadablePrivateKeyIsNotSilentlyReplaced(): void
    {
        $signer = new OfflineTokenSigner();
        $signer->publicKeyData($this->tenantId);
        $settings = new TenantSettingsRepository();
        $before = $settings->allForTenant($this->tenantId);
        $key = getenv('APP_KEY');
        putenv('APP_KEY=' . bin2hex(random_bytes(32)));
        try {
            $this->expectException(RuntimeException::class);
            $signer->publicKeyData($this->tenantId);
        } finally {
            putenv('APP_KEY=' . $key);
            self::assertSame($before, $settings->allForTenant($this->tenantId));
        }
    }

    public function testConcurrentFirstUseCreatesOneKeyPair(): void
    {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
        $stmt->execute([$this->tenantId]);
        $workers = [];
        $ids = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/offline-worker.php', (string) $this->tenantId],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes];
            }
            $this->pdo->commit();
            foreach ($workers as [, $pipes]) {
                $line = fgets($pipes[1]);
                self::assertIsString($line);
                $ids[] = trim($line);
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
        self::assertCount(1, array_unique($ids));
        self::assertSame($ids[0], (new OfflineTokenSigner())->publicKeyData($this->tenantId)['key_id']);
    }
}
