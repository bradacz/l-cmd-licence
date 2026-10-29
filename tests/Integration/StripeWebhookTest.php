<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/DatabaseTestCase.php';

use App\Repositories\TenantStripeConnectionRepository;
use App\Services\StripeWebhookService;

final class StripeWebhookTest extends DatabaseTestCase
{
    private string $secret;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = bin2hex(random_bytes(32));
        (new TenantStripeConnectionRepository())->upsert($this->tenantId, ['is_enabled' => true, 'webhook_secret' => $this->secret]);
        $stmt = $this->pdo->prepare('SELECT slug FROM apps WHERE id = ?');
        $stmt->execute([$this->appId]);
        $this->slug = $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->tenantId)) {
            $this->pdo->prepare('DELETE FROM webhook_receipts WHERE scope = ?')->execute(['tenant:' . $this->tenantId]);
        }
        parent::tearDown();
    }

    public function testUnpaidCheckoutDoesNotIssueLicense(): void
    {
        $object = $this->checkout('unpaid');
        $this->deliver('checkout.session.completed', $object);
        self::assertSame(0, $this->licenseCount());
        $object['payment_status'] = 'paid';
        $this->deliver('checkout.session.async_payment_succeeded', $object);
        self::assertSame(1, $this->licenseCount());
    }

    public function testRepeatedEventAndDifferentEventsForSameCheckoutIssueOneLicense(): void
    {
        $object = $this->checkout();
        $id = 'evt_' . bin2hex(random_bytes(16));
        $this->deliver('checkout.session.completed', $object, $id);
        $this->deliver('checkout.session.completed', $object, $id);
        $this->deliver('checkout.session.completed', $object);
        self::assertSame(1, $this->licenseCount());
    }

    public function testConcurrentEventsAcrossSigningScopesIssueOneLicense(): void
    {
        $globalSecret = bin2hex(random_bytes(32));
        $previous = getenv('STRIPE_WEBHOOK_SECRET');
        putenv('STRIPE_WEBHOOK_SECRET=' . $globalSecret);
        $workers = [];
        $ids = [];
        $object = $this->checkout();
        $this->pdo->beginTransaction();
        $lock = $this->pdo->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
        $lock->execute([$this->tenantId]);
        try {
            for ($i = 0; $i < 4; $i++) {
                $id = 'evt_' . bin2hex(random_bytes(16));
                $ids[] = $id;
                $payload = json_encode(['id' => $id, 'type' => 'checkout.session.completed', 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
                $time = time();
                $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, $i % 2 ? $globalSecret : $this->secret);
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/webhook-worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], json_encode(['payload' => $payload, 'signature' => $signature], JSON_THROW_ON_ERROR) . "\n");
                fflush($pipes[0]);
            }
            $this->pdo->commit();
            foreach ($workers as [, $pipes]) {
                self::assertSame("{\"received\":true}\n", fgets($pipes[1]));
            }
        } finally {
            putenv($previous === false ? 'STRIPE_WEBHOOK_SECRET' : 'STRIPE_WEBHOOK_SECRET=' . $previous);
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
            foreach ($ids as $id) {
                $this->pdo->prepare('DELETE FROM webhook_receipts WHERE event_id = ?')->execute([$id]);
            }
        }
        self::assertSame(1, $this->licenseCount());
    }

    public function testTenantSignatureCannotIssueLicenseForAnotherTenant(): void
    {
        $other = (int) $this->pdo->query("SELECT id FROM tenants WHERE slug = 'default'")->fetchColumn();
        $stmt = $this->pdo->prepare('UPDATE apps SET tenant_id = ? WHERE id = ?');
        $stmt->execute([$other, $this->appId]);
        try {
            $this->expectException(DomainException::class);
            $this->deliver('checkout.session.completed', $this->checkout());
        } finally {
            $stmt->execute([$this->tenantId, $this->appId]);
            self::assertSame(0, $this->licenseCount());
        }
    }

    public function testTenantSignatureCannotAuthorizePlatformSubscription(): void
    {
        $object = $this->checkout();
        $object['metadata']['purchase_kind'] = 'tenant_plan';
        $this->expectException(DomainException::class);
        $this->deliver('checkout.session.completed', $object);
    }

    public function testInvalidSignatureHasNoLicenseSideEffects(): void
    {
        $this->secret = 'invalid-fixture-key';
        try {
            $this->expectException(DomainException::class);
            $this->deliver('checkout.session.completed', $this->checkout());
        } finally {
            self::assertSame(0, $this->licenseCount());
        }
    }

    public function testStaleSignatureIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->deliver('checkout.session.completed', $this->checkout(), timestamp: time() - 600);
    }

    public function testForeignSubscriptionCannotBeCanceled(): void
    {
        $license = $this->license();
        $subscription = 'sub_' . bin2hex(random_bytes(12));
        $other = (int) $this->pdo->query("SELECT id FROM tenants WHERE slug = 'default'")->fetchColumn();
        $this->pdo->prepare('UPDATE licenses SET tenant_id = ?, stripe_subscription_id = ? WHERE id = ?')->execute([$other, $subscription, $license['id']]);
        try {
            $this->expectException(DomainException::class);
            $this->deliver('customer.subscription.deleted', ['id' => $subscription]);
        } finally {
            $stmt = $this->pdo->prepare('SELECT status FROM licenses WHERE id = ?');
            $stmt->execute([$license['id']]);
            self::assertSame('active', $stmt->fetchColumn());
            $this->pdo->prepare('UPDATE licenses SET tenant_id = ? WHERE id = ?')->execute([$this->tenantId, $license['id']]);
        }
    }

    public function testRenewalUsesPaidPeriodAndOlderInvoiceCannotShortenIt(): void
    {
        $license = $this->license(expires: gmdate('Y-m-d H:i:s', time() + 300));
        $subscription = 'sub_' . bin2hex(random_bytes(12));
        $this->pdo->prepare("UPDATE licenses SET license_type = 'monthly', stripe_subscription_id = ? WHERE id = ?")->execute([$subscription, $license['id']]);
        $end = time() + 86400 * 60;
        $object = ['id' => 'in_fixture', 'subscription' => $subscription, 'lines' => ['data' => [['subscription' => $subscription, 'period' => ['end' => $end]]]]];
        $this->deliver('invoice.payment_succeeded', $object);
        $object['lines']['data'][0]['period']['end'] -= 86400 * 30;
        $this->deliver('invoice.payment_succeeded', $object);
        $stmt = $this->pdo->prepare('SELECT expires_at FROM licenses WHERE id = ?');
        $stmt->execute([$license['id']]);
        self::assertSame(gmdate('Y-m-d H:i:s', $end), $stmt->fetchColumn());
    }

    private function checkout(string $paymentStatus = 'paid'): array
    {
        return [
            'id' => 'cs_test_' . bin2hex(random_bytes(12)), 'payment_status' => $paymentStatus,
            'customer_details' => ['email' => 'fixture@example.invalid', 'name' => 'Fixture'],
            'currency' => 'eur', 'amount_total' => 1000,
            'metadata' => ['license_type' => 'lifetime', 'app_slug' => $this->slug, 'product_slug' => 'fixture'],
        ];
    }

    private function deliver(string $type, array $object, ?string $id = null, ?int $timestamp = null): void
    {
        $id ??= 'evt_' . bin2hex(random_bytes(16));
        $timestamp ??= time();
        $payload = json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);
        self::assertSame(['received' => true], (new StripeWebhookService())->handle($payload, $signature));
    }

    private function licenseCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM licenses WHERE tenant_id = ?');
        $stmt->execute([$this->tenantId]);
        return (int) $stmt->fetchColumn();
    }
}
