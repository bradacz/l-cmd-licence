<?php

declare(strict_types=1);

use App\Database;
use App\Services\LicenseKeyGenerator;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected int $tenantId;
    protected int $appId;
    private static bool $schemaLoaded = false;

    protected function setUp(): void
    {
        if (getenv('LCMD_TEST_DB') !== '1') {
            self::markTestSkipped('Set LCMD_TEST_DB=1 and use a dedicated database ending in _test.');
        }
        $name = (string) getenv('DB_DATABASE');
        if (!preg_match('/^[a-zA-Z0-9_]+_test$/D', $name)) {
            self::fail('Refusing to run integration tests outside a dedicated *_test database.');
        }
        $this->pdo = Database::pdo();
        self::assertSame($name, $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        if (!self::$schemaLoaded) {
            $this->pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
            self::$schemaLoaded = true;
        }
        $slug = 'test-' . bin2hex(random_bytes(8));
        $stmt = $this->pdo->prepare("INSERT INTO tenants (slug, name, plan_key) VALUES (?, 'Regression fixture', 'free')");
        $stmt->execute([$slug]);
        $this->tenantId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare("INSERT INTO apps (tenant_id, slug, name) VALUES (?, ?, 'Test app')");
        $stmt->execute([$this->tenantId, $slug]);
        $this->appId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->tenantId)) {
            $this->pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$this->tenantId]);
        }
    }

    protected function license(string $status = 'active', int $limit = 1, ?string $expires = null): array
    {
        $key = (new LicenseKeyGenerator())->generate('lifetime', 'Test app');
        $stmt = $this->pdo->prepare('INSERT INTO licenses
            (tenant_id, app_id, license_key, license_type, customer_email, status, max_activations, expires_at)
            VALUES (?, ?, ?, \'lifetime\', \'customer@example.invalid\', ?, ?, ?)');
        $stmt->execute([$this->tenantId, $this->appId, $key, $status, $limit, $expires]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'key' => $key];
    }

    protected function policy(string $key, mixed $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO app_policies (tenant_id, app_id, policy_key, policy_value, is_enabled) VALUES (?, ?, ?, ?, 1)');
        $stmt->execute([$this->tenantId, $this->appId, $key, json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    protected function assertActivations(array $license, int $expected): void
    {
        $stmt = $this->pdo->prepare('SELECT current_activations FROM licenses WHERE id = ?');
        $stmt->execute([$license['id']]);
        self::assertSame($expected, (int) $stmt->fetchColumn());
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND is_active = 1');
        $stmt->execute([$license['id']]);
        self::assertSame($expected, (int) $stmt->fetchColumn());
    }
}
