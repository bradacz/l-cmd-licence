<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TenantSettingsRepository;
use App\Support\TenantPlanCatalog;

final class TenantBillingService
{
    public function __construct(
        private readonly TenantRepository $tenants = new TenantRepository(),
        private readonly TenantSettingsRepository $settings = new TenantSettingsRepository(),
        private readonly LicenseRepository $licenses = new LicenseRepository(),
        private readonly AppRepository $apps = new AppRepository()
    ) {
    }

    /** @return array<string,mixed> */
    public function summaryForTenant(int $tenantId): array
    {
        $tenant = $this->tenants->findById($tenantId);
        if ($tenant === null) {
            throw new \RuntimeException('Tenant not found.');
        }

        $planKey = $this->normalizedPlanKey((string) ($tenant['plan_key'] ?? 'free'));
        $plan = TenantPlanCatalog::definition($planKey);
        $settings = $this->settings->allForTenant($tenantId);
        $billingCurrency = TenantPlanCatalog::normalizeCurrency((string) ($settings['billing_currency'] ?? ($tenant['default_currency'] ?? 'EUR')));
        $activeLicenses = $this->licenses->countActiveForTenant($tenantId);
        $projectCount = $this->apps->countForTenant($tenantId);
        $included = $plan['included_active_licenses'];
        $overage = is_int($included) ? max(0, $activeLicenses - $included) : 0;
        $extraPrice = TenantPlanCatalog::extraActiveLicensePriceForCurrency($planKey, $billingCurrency);
        $projectedOverage = round($overage * $extraPrice, 2);

        return [
            'plan' => $plan,
            'billing' => [
                'status' => (string) ($settings['billing_status'] ?? ($planKey === 'free' ? 'free' : 'active')),
                'currency' => $billingCurrency,
                'stripe_customer_id' => (string) ($settings['billing_stripe_customer_id'] ?? ''),
                'stripe_subscription_id' => (string) ($settings['billing_stripe_subscription_id'] ?? ''),
                'stripe_price_id' => (string) ($settings['billing_stripe_price_id'] ?? ''),
                'current_period_starts_at' => (string) ($settings['billing_current_period_starts_at'] ?? ''),
                'current_period_ends_at' => (string) ($settings['billing_current_period_ends_at'] ?? ''),
                'overage_invoice_item_id' => (string) ($settings['billing_overage_invoice_item_id'] ?? ''),
                'overage_synced_quantity' => (int) ($settings['billing_overage_synced_quantity'] ?? 0),
                'overage_synced_amount_minor' => (int) ($settings['billing_overage_synced_amount_minor'] ?? 0),
                'overage_synced_at' => (string) ($settings['billing_overage_synced_at'] ?? ''),
                'overage_sync_status' => (string) ($settings['billing_overage_sync_status'] ?? ''),
                'overage_sync_error' => (string) ($settings['billing_overage_sync_error'] ?? ''),
            ],
            'usage' => [
                'active_licenses' => $activeLicenses,
                'included_active_licenses' => $included,
                'overage_active_licenses' => $overage,
                'extra_active_license_price' => $extraPrice,
                'extra_active_license_price_currency' => $billingCurrency,
                'projected_overage_amount' => $projectedOverage,
                'projected_overage_currency' => $billingCurrency,
                'extra_active_license_price_eur' => $extraPrice,
                'projected_overage_amount_eur' => $billingCurrency === 'EUR' ? $projectedOverage : 0.0,
                'project_count' => $projectCount,
            ],
        ];
    }

    public function assertCanCreateProject(int $tenantId): void
    {
        $summary = $this->summaryForTenant($tenantId);
        if (!TenantPlanCatalog::hasFeature((string) $summary['plan']['key'], 'multi_project') && (int) $summary['usage']['project_count'] >= 1) {
            throw new \RuntimeException('Current plan does not include multi-project support.');
        }
    }

    public function assertFeatureEnabled(int $tenantId, string $featureKey): void
    {
        $summary = $this->summaryForTenant($tenantId);
        if (!TenantPlanCatalog::hasFeature((string) $summary['plan']['key'], $featureKey)) {
            throw new \RuntimeException('Current plan does not include this feature.');
        }
    }

    public function assertCanIssueActiveLicense(int $tenantId): void
    {
        $summary = $this->summaryForTenant($tenantId);
        $hardLimit = $summary['plan']['hard_active_license_limit'];
        if (is_int($hardLimit) && (int) $summary['usage']['active_licenses'] >= $hardLimit) {
            throw new \RuntimeException('Active license limit reached for current plan.');
        }
    }

    /** @param array<string,mixed> $payload */
    public function updateTenantBilling(int $tenantId, array $payload): void
    {
        $planKey = $this->normalizedPlanKey((string) ($payload['plan_key'] ?? 'free'));
        $this->tenants->update($tenantId, [
            'plan_key' => $planKey,
        ]);

        $map = [
            'billing_status' => 'billing_status',
            'currency' => 'billing_currency',
            'stripe_customer_id' => 'billing_stripe_customer_id',
            'stripe_subscription_id' => 'billing_stripe_subscription_id',
            'stripe_price_id' => 'billing_stripe_price_id',
            'current_period_starts_at' => 'billing_current_period_starts_at',
            'current_period_ends_at' => 'billing_current_period_ends_at',
        ];

        foreach ($map as $inputKey => $settingKey) {
            $rawValue = trim((string) ($payload[$inputKey] ?? ''));
            if ($inputKey === 'currency' && $rawValue !== '') {
                $rawValue = TenantPlanCatalog::normalizeCurrency($rawValue);
                $this->tenants->update($tenantId, ['default_currency' => $rawValue]);
            }
            $this->settings->upsert($tenantId, $settingKey, $rawValue !== '' ? $rawValue : null);
        }
    }

    public function normalizedPlanKey(string $planKey): string
    {
        return TenantPlanCatalog::definition($planKey)['key'];
    }
}
