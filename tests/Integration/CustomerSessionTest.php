<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/DatabaseTestCase.php';

use App\Repositories\TenantMembershipRepository;
use App\Services\CustomerSessionService;

final class CustomerSessionTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $stmt = $this->pdo->prepare('INSERT INTO tenant_users (email, password_hash) VALUES (?, ?)');
        $stmt->execute(['test-' . bin2hex(random_bytes(8)) . '@example.invalid', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        $this->userId = (int) $this->pdo->lastInsertId();
        (new TenantMembershipRepository())->create($this->tenantId, $this->userId);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
        if (isset($this->userId)) {
            $this->pdo->prepare('DELETE FROM tenant_users WHERE id = ?')->execute([$this->userId]);
        }
    }

    public function testDisablingUserInvalidatesExistingSession(): void
    {
        $session = new CustomerSessionService();
        $session->login($this->userId, $this->tenantId);
        self::assertNotNull($session->current());
        $this->pdo->prepare('UPDATE tenant_users SET is_active = 0 WHERE id = ?')->execute([$this->userId]);
        self::assertNull($session->current());
        self::assertSame([], $_SESSION);
        $this->expectException(DomainException::class);
        $session->login($this->userId, $this->tenantId);
    }

    public function testSuspendingTenantInvalidatesExistingSession(): void
    {
        $session = new CustomerSessionService();
        $session->login($this->userId, $this->tenantId);
        $this->pdo->prepare("UPDATE tenants SET status = 'suspended' WHERE id = ?")->execute([$this->tenantId]);
        self::assertNull($session->current());
        self::assertSame([], (new TenantMembershipRepository())->activeMembershipsForUser($this->userId));
    }

    public function testTrialTenantCanStillLogin(): void
    {
        $this->pdo->prepare("UPDATE tenants SET status = 'trial' WHERE id = ?")->execute([$this->tenantId]);
        $session = new CustomerSessionService();
        $session->login($this->userId, $this->tenantId);
        self::assertSame($this->tenantId, $session->current()['tenant_id']);
    }

    public function testPasswordResetInvalidatesSessionAndTokenCannotBeReused(): void
    {
        $session = new CustomerSessionService();
        $session->login($this->userId, $this->tenantId);
        $tokens = new App\Repositories\TenantPasswordResetTokenRepository();
        $token = bin2hex(random_bytes(32));
        $tokens->create($this->userId, $token, gmdate('Y-m-d H:i:s', time() + 300));
        $auth = new App\Services\CustomerAuthService();
        $auth->resetPassword($token, bin2hex(random_bytes(16)));
        self::assertNull($session->current());
        $this->expectException(DomainException::class);
        $auth->resetPassword($token, bin2hex(random_bytes(16)));
    }

    public function testResetTokenCannotReactivateDisabledUser(): void
    {
        $token = bin2hex(random_bytes(32));
        (new App\Repositories\TenantPasswordResetTokenRepository())->create($this->userId, $token, gmdate('Y-m-d H:i:s', time() + 300));
        $this->pdo->prepare('UPDATE tenant_users SET is_active = 0 WHERE id = ?')->execute([$this->userId]);
        try {
            $this->expectException(DomainException::class);
            (new App\Services\CustomerAuthService())->resetPassword($token, bin2hex(random_bytes(16)));
        } finally {
            $user = (new App\Repositories\TenantUserRepository())->findById($this->userId);
            self::assertSame(0, (int) $user['is_active']);
        }
    }

    public function testActivationCannotReplaceExistingUsersPassword(): void
    {
        $repository = new App\Repositories\TenantUserRepository();
        $before = $repository->findById($this->userId);
        $key = bin2hex(random_bytes(32));
        (new App\Repositories\TenantAccessKeyRepository())->create($this->tenantId, $before['email'], $key);
        $auth = new App\Services\CustomerAuthService();
        $activation = $auth->beginActivation($before['email'], $key);
        try {
            $this->expectException(DomainException::class);
            $auth->completeActivation(bin2hex(random_bytes(16)), activationToken: $activation['activation_token']);
        } finally {
            self::assertSame($before['password_hash'], $repository->findById($this->userId)['password_hash']);
        }
    }

    public function testExistingAccountActivationAcceptsItsUnchangedLegacyPassword(): void
    {
        $password = bin2hex(random_bytes(4));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->pdo->prepare('UPDATE tenant_users SET password_hash = ? WHERE id = ?')->execute([$hash, $this->userId]);
        $repository = new App\Repositories\TenantUserRepository();
        $before = $repository->findById($this->userId);
        $key = bin2hex(random_bytes(32));
        (new App\Repositories\TenantAccessKeyRepository())->create($this->tenantId, $before['email'], $key);
        $auth = new App\Services\CustomerAuthService();
        $activation = $auth->beginActivation($before['email'], $key);
        $created = $auth->completeActivation($password, activationToken: $activation['activation_token']);
        self::assertSame($this->userId, $created['tenant_user_id']);
        self::assertSame($hash, $repository->findById($this->userId)['password_hash']);
    }

    public function testNewAccountActivationIsSingleUse(): void
    {
        $email = 'new-' . bin2hex(random_bytes(12)) . '@example.invalid';
        $key = bin2hex(random_bytes(32));
        (new App\Repositories\TenantAccessKeyRepository())->create($this->tenantId, $email, $key);
        $auth = new App\Services\CustomerAuthService();
        $activation = $auth->beginActivation($email, $key);
        $password = bin2hex(random_bytes(16));
        $created = $auth->completeActivation($password, activationToken: $activation['activation_token']);
        try {
            self::assertSame($email, $created['email']);
            $this->expectException(DomainException::class);
            $auth->completeActivation($password, activationToken: $activation['activation_token']);
        } finally {
            $this->pdo->prepare('DELETE FROM tenant_users WHERE id = ?')->execute([$created['tenant_user_id']]);
        }
    }
}
