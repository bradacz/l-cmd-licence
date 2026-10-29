<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Repositories\TenantAccessKeyRepository;
use App\Repositories\TenantActivationTokenRepository;
use App\Repositories\TenantMembershipRepository;
use App\Repositories\TenantPasswordResetTokenRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TenantUserRepository;
use App\Support\OpaqueToken;

final class CustomerAuthService
{
    private const MIN_PASSWORD_LENGTH = 10;
    /** @var list<string> */
    private const COMMON_WEAK_PASSWORDS = [
        '12345678',
        '123456789',
        '1234567890',
        'password',
        'password123',
        'qwerty123',
        'qwertyuiop',
    ];

    public static function passwordPolicyMessage(): string
    {
        return sprintf('Password must be at least %d characters long and must not be a commonly used password.', self::MIN_PASSWORD_LENGTH);
    }

    public static function isPasswordAcceptable(string $password, ?string $email = null): bool
    {
        return self::passwordPolicyViolation($password, $email) === null;
    }

    /** @return array<string,mixed> */
    public function issueAccessKey(int $tenantId, string $email, ?int $adminUserId = null, bool $sendEmail = true): array
    {
        $accessKeys = new TenantAccessKeyRepository();
        $accessKeys->expirePendingForTenantEmail($tenantId, $email);
        $key = OpaqueToken::generate('activate');
        $expiresAt = date('Y-m-d H:i:s', time() + (72 * 3600));
        $accessKeyId = $accessKeys->create($tenantId, $email, $key, $expiresAt, $adminUserId);

        $sent = false;
        if ($sendEmail) {
            $subject = 'Your first access key - L-CMD License Server';
            $text = "Use this activation key for your first login:\n\n{$key}\n\nThis key expires in 72 hours.";
            $html = '<p>Use this activation key for your first login:</p><p><strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong></p><p>This key expires in 72 hours.</p>';
            $result = (new CustomerSmtpService())->sendRawEmail($tenantId, $email, $subject, $html, $text);
            $sent = (bool) ($result['success'] ?? false);
            if (!$sent) {
                $sent = (new MailService())->sendGenericEmail($email, $subject, $html, $text);
            }
        }

        return [
            'access_key_id' => $accessKeyId,
            'email' => mb_strtolower(trim($email)),
            'activation_key' => $key,
            'expires_at' => $expiresAt,
            'email_sent' => $sent,
        ];
    }

    /** @return array<string,mixed> */
    public function issueOnboardingAccessKey(int $tenantId, string $email, string $portalUrl, string $planLabel): array
    {
        $accessKeys = new TenantAccessKeyRepository();
        $accessKeys->expirePendingForTenantEmail($tenantId, $email);
        $key = OpaqueToken::generate('activate');
        $expiresAt = date('Y-m-d H:i:s', time() + (72 * 3600));
        $accessKeyId = $accessKeys->create($tenantId, $email, $key, $expiresAt, null);

        $html = '<p>Your SimplyKeys workspace is ready.</p><p><strong>Plan:</strong> '
            . htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8')
            . '</p><p><strong>Access key:</strong> '
            . htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
            . '</p><p>Continue at <a href="'
            . htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8')
            . '">'
            . htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8')
            . '</a>.</p>';
        $text = "Your SimplyKeys workspace is ready.\n\nPlan: {$planLabel}\nAccess key: {$key}\nPortal: {$portalUrl}\n";

        $result = (new CustomerSmtpService())->sendRawEmail($tenantId, $email, 'Your SimplyKeys access is ready', $html, $text);
        $sent = (bool) ($result['success'] ?? false);
        if (!$sent) {
            $sent = (new MailService())->sendTenantOnboarding($email, $key, $portalUrl, $planLabel);
        }

        return [
            'access_key_id' => $accessKeyId,
            'email' => mb_strtolower(trim($email)),
            'activation_key' => $key,
            'expires_at' => $expiresAt,
            'email_sent' => $sent,
        ];
    }

    public function beginActivation(string $email, string $accessKey, ?string $ip = null, ?string $userAgent = null): array
    {
        $record = (new TenantAccessKeyRepository())->findPendingByEmailAndKey($email, $accessKey);
        if ($record === null) {
            throw new \DomainException('Invalid activation key or email.');
        }

        $tenant = (new TenantRepository())->findById((int) $record['tenant_id']);
        if ($tenant === null || !in_array($tenant['status'], ['active', 'trial'], true)) {
            throw new \DomainException('Tenant is unavailable.');
        }

        $activationTokens = new TenantActivationTokenRepository();
        $activationTokens->expirePendingForAccessKey((int) $record['id']);
        $activationToken = OpaqueToken::generate('activation');
        $activationTokenId = $activationTokens->create(
            (int) $record['tenant_id'],
            (int) $record['id'],
            (string) $record['email'],
            $activationToken,
            date('Y-m-d H:i:s', time() + 900),
            $ip,
            $userAgent
        );

        (new CustomerSessionService())->beginActivation([
            'access_key_id' => (int) $record['id'],
            'tenant_id' => (int) $record['tenant_id'],
            'email' => (string) $record['email'],
        ]);

        return [
            'tenant' => [
                'id' => (int) $tenant['id'],
                'slug' => (string) $tenant['slug'],
                'name' => (string) $tenant['name'],
            ],
            'email' => (string) $record['email'],
            'activation_token' => $activationToken,
            'activation_token_id' => $activationTokenId,
        ];
    }

    public function completeActivation(string $password, ?string $name = null, ?string $activationToken = null): array
    {
        $session = new CustomerSessionService();
        $accessKeys = new TenantAccessKeyRepository();
        $activationTokens = new TenantActivationTokenRepository();

        $pending = null;
        $activationTokenRecord = null;
        if ($activationToken !== null && trim($activationToken) !== '') {
            $activationTokenRecord = $activationTokens->findValidByToken(trim($activationToken));
            if ($activationTokenRecord === null) {
                throw new \DomainException('Activation token is invalid or expired.');
            }

            $pending = [
                'access_key_id' => (int) $activationTokenRecord['tenant_access_key_id'],
                'tenant_id' => (int) $activationTokenRecord['tenant_id'],
                'email' => (string) $activationTokenRecord['email'],
            ];
        } else {
            $pending = $session->pendingActivation();
            if ($pending === null) {
                throw new \DomainException('Activation session not found.');
            }
        }

        $users = new TenantUserRepository();
        $memberships = new TenantMembershipRepository();
        $result = Database::transaction(function () use ($pending, $activationToken, $activationTokens, $accessKeys, $session, $users, $memberships, $password, $name): array {
            $record = $accessKeys->findPendingById((int) $pending['access_key_id'], true);
            if ($record === null) {
                $session->clearPendingActivation();
                throw new \DomainException('Activation key is no longer valid.');
            }
            $activationTokenRecord = null;
            if ($activationToken !== null && trim($activationToken) !== '') {
                $activationTokenRecord = $activationTokens->findValidByToken(trim($activationToken), true);
                if ($activationTokenRecord === null || (int) $activationTokenRecord['tenant_access_key_id'] !== (int) $record['id']) {
                    throw new \DomainException('Activation token is invalid or expired.');
                }
            }
            $tenant = (new TenantRepository())->findById((int) $record['tenant_id']);
            if ($tenant === null || !in_array($tenant['status'], ['active', 'trial'], true)) {
                throw new \DomainException('Tenant is unavailable.');
            }
            $user = $users->findByEmail((string) $record['email']);
            if ($user === null) {
                $this->assertPasswordPolicy($password, (string) $record['email']);
                $userId = $users->create((string) $record['email'], password_hash($password, PASSWORD_DEFAULT), $name);
            } else {
                // An invitation must never reset an existing user's global password.
                if ((int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash'])) {
                    throw new \DomainException('For an existing account, use its current password or the password-reset flow.');
                }
                $userId = (int) $user['id'];
            }

            $memberships->ensureActiveMembership((int) $record['tenant_id'], $userId, 'owner');
            $accessKeys->markUsed((int) $record['id'], $userId);
            if ($activationTokenRecord !== null) {
                $activationTokens->markUsed((int) $activationTokenRecord['id']);
            }

            return [
                'tenant_user_id' => $userId,
                'tenant_id' => (int) $record['tenant_id'],
                'email' => (string) $record['email'],
            ];
        });
        $session->clearPendingActivation();
        $session->login($result['tenant_user_id'], $result['tenant_id']);
        return $result;
    }

    public function createPasswordReset(string $email): void
    {
        $user = (new TenantUserRepository())->findByEmail($email);
        if ($user === null || (int) ($user['is_active'] ?? 0) !== 1) {
            return;
        }

        $token = OpaqueToken::generate('reset');
        $expiresAt = date('Y-m-d H:i:s', time() + 7200);
        (new TenantPasswordResetTokenRepository())->create((int) $user['id'], $token, $expiresAt);

        $memberships = (new TenantMembershipRepository())->activeMembershipsForUser((int) $user['id']);
        $tenantId = isset($memberships[0]['tenant_id']) ? (int) $memberships[0]['tenant_id'] : null;
        $subject = 'Reset password - L-CMD License Server';
        $text = "Use this reset token to set a new password:\n\n{$token}\n\nThis token expires in 2 hours.";
        $html = '<p>Use this reset token to set a new password:</p><p><strong>' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '</strong></p><p>This token expires in 2 hours.</p>';

        if ($tenantId !== null) {
            $result = (new CustomerSmtpService())->sendRawEmail($tenantId, (string) $user['email'], $subject, $html, $text);
            if (($result['success'] ?? false) === true) {
                return;
            }
        }

        (new MailService())->sendGenericEmail((string) $user['email'], $subject, $html, $text);
    }

    public function resetPassword(string $token, string $password): void
    {
        $this->assertPasswordPolicy($password);

        $tokens = new TenantPasswordResetTokenRepository();
        Database::transaction(function () use ($tokens, $token, $password): void {
            $record = $tokens->findValidByToken($token, true);
            if ($record === null) {
                throw new \DomainException('Invalid or expired reset token.');
            }
            $user = (new TenantUserRepository())->findById((int) $record['tenant_user_id']);
            if ($user === null || (int) $user['is_active'] !== 1) {
                throw new \DomainException('Invalid or expired reset token.');
            }
            $this->assertPasswordPolicy($password, (string) $user['email']);
            (new TenantUserRepository())->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
            $tokens->markUsed((int) $record['id']);
        });
    }

    private function assertPasswordPolicy(string $password, ?string $email = null): void
    {
        $violation = self::passwordPolicyViolation($password, $email);
        if ($violation !== null) {
            throw new \DomainException($violation);
        }
    }

    private static function passwordPolicyViolation(string $password, ?string $email = null): ?string
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return self::passwordPolicyMessage();
        }

        $normalized = mb_strtolower(trim($password));
        if (in_array($normalized, self::COMMON_WEAK_PASSWORDS, true)) {
            return self::passwordPolicyMessage();
        }

        if ($email !== null) {
            $normalizedEmail = mb_strtolower(trim($email));
            if ($normalizedEmail !== '' && str_contains($normalized, $normalizedEmail)) {
                return 'Password must not contain your email address.';
            }
        }

        return null;
    }
}
