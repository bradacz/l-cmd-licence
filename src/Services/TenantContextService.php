<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TenantRepository;

final class TenantContextService
{
    private TenantRepository $tenants;
    private ?int $defaultTenantId = null;

    public function __construct()
    {
        $this->tenants = new TenantRepository();
    }

    public function defaultTenantId(): int
    {
        if ($this->defaultTenantId === null) {
            $this->defaultTenantId = $this->tenants->defaultTenantId();
        }

        return $this->defaultTenantId;
    }

    public function tenantIdForAppId(int $appId): int
    {
        return $this->tenants->tenantIdForAppId($appId) ?? $this->defaultTenantId();
    }

    public function tenantIdForProductId(int $productId): int
    {
        return $this->tenants->tenantIdForProductId($productId) ?? $this->defaultTenantId();
    }

    public function tenantIdForTariffId(int $tariffId): int
    {
        return $this->tenants->tenantIdForTariffId($tariffId) ?? $this->defaultTenantId();
    }

    public function tenantIdForLicenseId(int $licenseId): int
    {
        return $this->tenants->tenantIdForLicenseId($licenseId) ?? $this->defaultTenantId();
    }
}
