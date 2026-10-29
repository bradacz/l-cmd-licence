<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/DatabaseTestCase.php';

use App\Repositories\TenantSmtpConnectionRepository;
use App\Repositories\TenantStripeConnectionRepository;
use App\Services\CustomerStripeService;
use App\Support\SecretBox;

final class ConnectionsTest extends DatabaseTestCase
{
    public function testLegacyStripeSecretsMigrateWithoutRecursionAndRemainReadable(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO tenant_stripe_connections
            (tenant_id, secret_key_encrypted, webhook_secret_encrypted) VALUES (?, ?, ?)');
        $stmt->execute([$this->tenantId, $this->legacy('fixture-key'), $this->legacy('fixture-webhook')]);
        $repository = new TenantStripeConnectionRepository();
        $first = $repository->findByTenantId($this->tenantId);
        self::assertSame('fixture-key', $first['secret_key']);
        self::assertSame('fixture-webhook', $first['webhook_secret']);
        $stmt = $this->pdo->prepare('SELECT secret_key_encrypted, webhook_secret_encrypted FROM tenant_stripe_connections WHERE tenant_id = ?');
        $stmt->execute([$this->tenantId]);
        $stored = $stmt->fetch();
        foreach ($stored as $ciphertext) {
            self::assertFalse(SecretBox::needsReencrypt($ciphertext));
        }
        self::assertSame($first, $repository->findByTenantId($this->tenantId));
        $stmt->execute([$this->tenantId]);
        self::assertSame($stored, $stmt->fetch(), 'A second read must not encrypt the secrets again.');
    }

    public function testMigrationPreservesUnreadableCompanionSecret(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO tenant_stripe_connections
            (tenant_id, secret_key_encrypted, webhook_secret_encrypted) VALUES (?, ?, ?)');
        $stmt->execute([$this->tenantId, $this->legacy('fixture-key'), 'invalid-ciphertext']);
        (new TenantStripeConnectionRepository())->findByTenantId($this->tenantId);
        $stmt = $this->pdo->prepare('SELECT webhook_secret_encrypted FROM tenant_stripe_connections WHERE tenant_id = ?');
        $stmt->execute([$this->tenantId]);
        self::assertSame('invalid-ciphertext', $stmt->fetchColumn());
    }

    public function testLegacySmtpPasswordMigratesWithoutRecursionOrWhitespaceLoss(): void
    {
        $password = '  fixture password  ';
        $stmt = $this->pdo->prepare('INSERT INTO tenant_smtp_connections (tenant_id, password_encrypted) VALUES (?, ?)');
        $stmt->execute([$this->tenantId, $this->legacy($password)]);
        $repository = new TenantSmtpConnectionRepository();
        self::assertSame($password, $repository->findByTenantId($this->tenantId)['password']);
        $repository->upsert($this->tenantId, ['host' => 'smtp.example.invalid']);
        self::assertSame($password, $repository->findByTenantId($this->tenantId)['password']);
        $stmt = $this->pdo->prepare('SELECT password_encrypted FROM tenant_smtp_connections WHERE tenant_id = ?');
        $stmt->execute([$this->tenantId]);
        $stored = $stmt->fetchColumn();
        self::assertFalse(SecretBox::needsReencrypt($stored));
        self::assertSame($password, SecretBox::decrypt($stored));
    }

    public function testPartialStripeUpdateAndMaskedRoundTripPreserveAllKeys(): void
    {
        $repository = new TenantStripeConnectionRepository();
        $repository->upsert($this->tenantId, [
            'publishable_key' => 'fixture-publishable-key',
            'secret_key' => 'fixture-secret-key',
            'webhook_secret' => 'fixture-webhook-secret',
        ]);
        $before = $repository->findByTenantId($this->tenantId);
        $service = new CustomerStripeService();
        $service->update($this->tenantId, ['is_enabled' => true]);
        $service->update($this->tenantId, $service->getConfig($this->tenantId));
        $after = $repository->findByTenantId($this->tenantId);
        foreach (['publishable_key', 'secret_key', 'webhook_secret'] as $field) {
            self::assertSame($before[$field], $after[$field]);
        }
        self::assertTrue($after['is_enabled']);
        $service->update($this->tenantId, ['webhook_secret' => '']);
        self::assertSame('', $repository->findByTenantId($this->tenantId)['webhook_secret']);
    }

    private function legacy(string $plaintext): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', hash('sha256', getenv('APP_KEY'), true), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }
}
