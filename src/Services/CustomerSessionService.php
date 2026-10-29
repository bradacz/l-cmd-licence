<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TenantMembershipRepository;
use App\Repositories\TenantUserRepository;

final class CustomerSessionService
{
    private const SESSION_KEY = 'customer_auth';
    private const ACTIVATION_SESSION_KEY = 'customer_activation';

    public function login(int $tenantUserId, ?int $tenantId = null): void
    {
        $user = (new TenantUserRepository())->findById($tenantUserId);
        if ($user === null || (int) $user['is_active'] !== 1) {
            throw new \DomainException('User account is inactive.');
        }
        $memberships = (new TenantMembershipRepository())->activeMembershipsForUser($tenantUserId);
        if ($memberships === []) {
            throw new \RuntimeException('No active tenant membership found.');
        }

        $membership = $tenantId !== null
            ? (new TenantMembershipRepository())->activeMembershipForUserInTenant($tenantUserId, $tenantId)
            : $memberships[0];
        if ($membership === null) {
            throw new \RuntimeException('Selected tenant membership not found.');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = [
            'tenant_user_id' => $tenantUserId,
            'tenant_id' => (int) $membership['tenant_id'],
            'role' => (string) $membership['role'],
            'login_at' => time(),
            'password_version' => hash('sha256', (string) $user['password_hash']),
        ];

        (new TenantUserRepository())->updateLastLoginAt($tenantUserId);
    }

    public function switchTenant(int $tenantUserId, int $tenantId): void
    {
        $membership = (new TenantMembershipRepository())->activeMembershipForUserInTenant($tenantUserId, $tenantId);
        if ($membership === null) {
            throw new \RuntimeException('Selected tenant membership not found.');
        }

        $auth = $_SESSION[self::SESSION_KEY] ?? [];
        $_SESSION[self::SESSION_KEY] = [
            'tenant_user_id' => $tenantUserId,
            'tenant_id' => (int) $membership['tenant_id'],
            'role' => (string) $membership['role'],
            'login_at' => (int) ($auth['login_at'] ?? time()),
            'password_version' => (string) ($auth['password_version'] ?? ''),
        ];
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::ACTIVATION_SESSION_KEY]);

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'] ?: '/',
                    'domain' => $params['domain'] ?: '',
                    'secure' => (bool) ($params['secure'] ?? false),
                    'httponly' => (bool) ($params['httponly'] ?? true),
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** @param array<string,mixed> $payload */
    public function beginActivation(array $payload): void
    {
        $_SESSION[self::ACTIVATION_SESSION_KEY] = [
            'access_key_id' => (int) ($payload['access_key_id'] ?? 0),
            'tenant_id' => (int) ($payload['tenant_id'] ?? 0),
            'email' => (string) ($payload['email'] ?? ''),
            'created_at' => time(),
        ];
    }

    /** @return array<string,mixed>|null */
    public function pendingActivation(): ?array
    {
        $pending = $_SESSION[self::ACTIVATION_SESSION_KEY] ?? null;
        if (!is_array($pending)) {
            return null;
        }

        if (((int) ($pending['created_at'] ?? 0)) <= 0 || (time() - (int) $pending['created_at']) > 1800) {
            $this->clearPendingActivation();
            return null;
        }

        return $pending;
    }

    public function clearPendingActivation(): void
    {
        unset($_SESSION[self::ACTIVATION_SESSION_KEY]);
    }

    /** @return array<string,mixed>|null */
    public function current(): ?array
    {
        $auth = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($auth)) {
            return null;
        }

        $loginAt = (int) ($auth['login_at'] ?? 0);
        if ($loginAt <= 0 || (time() - $loginAt) > (60 * 60 * 8)) {
            $this->logout();
            return null;
        }

        $userId = (int) ($auth['tenant_user_id'] ?? 0);
        $tenantId = (int) ($auth['tenant_id'] ?? 0);
        if ($userId <= 0 || $tenantId <= 0) {
            $this->logout();
            return null;
        }

        $user = (new TenantUserRepository())->findById($userId);
        $memberships = (new TenantMembershipRepository())->activeMembershipsForUser($userId);
        $membership = (new TenantMembershipRepository())->activeMembershipForUserInTenant($userId, $tenantId);
        if ($user === null || $membership === null || (int) $membership['tenant_id'] !== $tenantId
            || !hash_equals(hash('sha256', (string) $user['password_hash']), (string) ($auth['password_version'] ?? ''))) {
            $this->logout();
            return null;
        }

        return [
            'tenant_user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => (string) $membership['role'],
            'user' => $user,
            'membership' => $membership,
            'memberships' => $memberships,
        ];
    }
}
