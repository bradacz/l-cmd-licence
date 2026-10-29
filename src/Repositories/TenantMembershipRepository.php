<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class TenantMembershipRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function create(int $tenantId, int $tenantUserId, string $role = 'owner'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_memberships (tenant_id, tenant_user_id, role, is_active)
             VALUES (:tenant_id, :tenant_user_id, :role, 1)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'tenant_user_id' => $tenantUserId,
            'role' => $role,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function ensureActiveMembership(int $tenantId, int $tenantUserId, string $role = 'owner'): void
    {
        $existing = $this->activeMembershipForUserInTenant($tenantUserId, $tenantId);
        if ($existing !== null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM tenant_memberships WHERE tenant_id = :tenant_id AND tenant_user_id = :tenant_user_id LIMIT 1'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'tenant_user_id' => $tenantUserId,
        ]);
        $row = $stmt->fetch();

        if ($row) {
            $reactivate = $this->pdo->prepare(
                'UPDATE tenant_memberships SET is_active = 1, role = :role WHERE id = :id'
            );
            $reactivate->execute([
                'id' => (int) $row['id'],
                'role' => $role,
            ]);

            return;
        }

        $this->create($tenantId, $tenantUserId, $role);
    }

    /** @return array<string,mixed>|null */
    public function activeMembershipForUser(int $tenantUserId): ?array
    {
        $memberships = $this->activeMembershipsForUser($tenantUserId);

        return $memberships[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function activeMembershipForUserInTenant(int $tenantUserId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, t.slug AS tenant_slug, t.name AS tenant_name, t.status AS tenant_status, t.plan_key, t.timezone, t.default_currency, t.is_demo
             FROM tenant_memberships m
             INNER JOIN tenants t ON t.id = m.tenant_id
             INNER JOIN tenant_users u ON u.id = m.tenant_user_id
             WHERE m.tenant_user_id = :tenant_user_id AND m.tenant_id = :tenant_id AND m.is_active = 1
                 AND u.is_active = 1 AND t.status IN (\'active\', \'trial\')
             LIMIT 1'
        );
        $stmt->execute([
            'tenant_user_id' => $tenantUserId,
            'tenant_id' => $tenantId,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function activeMembershipsForUser(int $tenantUserId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, t.slug AS tenant_slug, t.name AS tenant_name, t.status AS tenant_status, t.plan_key, t.timezone, t.default_currency, t.is_demo
             FROM tenant_memberships m
             INNER JOIN tenants t ON t.id = m.tenant_id
             INNER JOIN tenant_users u ON u.id = m.tenant_user_id
             WHERE m.tenant_user_id = :tenant_user_id AND m.is_active = 1
                 AND u.is_active = 1 AND t.status IN (\'active\', \'trial\')
             ORDER BY m.id ASC
            '
        );
        $stmt->execute(['tenant_user_id' => $tenantUserId]);
        $rows = $stmt->fetchAll() ?: [];

        return $rows;
    }
}
