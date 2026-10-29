<?php

declare(strict_types=1);

namespace App\Support;

final class TenantPlanCatalog
{
    /** @return array<string,float> */
    private static function prices(float $czk, float $eur, float $usd): array
    {
        return [
            'CZK' => $czk,
            'EUR' => $eur,
            'USD' => $usd,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            'internal' => [
                'key' => 'internal',
                'label' => 'Internal',
                'prices' => self::prices(0.0, 0.0, 0.0),
                'price_eur' => 0.0,
                'interval' => 'forever',
                'included_active_licenses' => null,
                'extra_active_license_prices' => self::prices(0.0, 0.0, 0.0),
                'extra_active_license_price_eur' => 0.0,
                'hard_active_license_limit' => null,
                'analytics_level' => 'advanced',
                'features' => [
                    'basic_api' => true,
                    'stripe_integration' => true,
                    'community_support' => false,
                    'email_support' => true,
                    'priority_support' => true,
                    'webhooks' => true,
                    'analytics' => true,
                    'hardware_locking' => true,
                    'offline_licenses' => true,
                    'multi_project' => true,
                ],
            ],
            'demo' => [
                'key' => 'demo',
                'label' => 'Demo',
                'prices' => self::prices(0.0, 0.0, 0.0),
                'price_eur' => 0.0,
                'interval' => 'forever',
                'included_active_licenses' => null,
                'extra_active_license_prices' => self::prices(0.0, 0.0, 0.0),
                'extra_active_license_price_eur' => 0.0,
                'hard_active_license_limit' => null,
                'analytics_level' => 'advanced',
                'features' => [
                    'basic_api' => true,
                    'stripe_integration' => true,
                    'community_support' => true,
                    'email_support' => true,
                    'priority_support' => false,
                    'webhooks' => true,
                    'analytics' => true,
                    'hardware_locking' => true,
                    'offline_licenses' => true,
                    'multi_project' => true,
                ],
            ],
            'free' => [
                'key' => 'free',
                'label' => 'Free',
                'prices' => self::prices(0.0, 0.0, 0.0),
                'price_eur' => 0.0,
                'interval' => 'forever',
                'included_active_licenses' => 20,
                'extra_active_license_prices' => self::prices(0.0, 0.0, 0.0),
                'extra_active_license_price_eur' => 0.0,
                'hard_active_license_limit' => 20,
                'analytics_level' => 'none',
                'features' => [
                    'basic_api' => true,
                    'stripe_integration' => true,
                    'community_support' => true,
                    'email_support' => false,
                    'priority_support' => false,
                    'webhooks' => false,
                    'analytics' => false,
                    'hardware_locking' => false,
                    'offline_licenses' => false,
                    'multi_project' => false,
                ],
            ],
            'starter' => [
                'key' => 'starter',
                'label' => 'Starter',
                'prices' => self::prices(990.0, 39.0, 47.0),
                'price_eur' => 39.0,
                'interval' => 'month',
                'included_active_licenses' => 200,
                'extra_active_license_prices' => self::prices(5.0, 0.20, 0.20),
                'extra_active_license_price_eur' => 0.20,
                'hard_active_license_limit' => null,
                'analytics_level' => 'none',
                'features' => [
                    'basic_api' => true,
                    'stripe_integration' => true,
                    'community_support' => false,
                    'email_support' => true,
                    'priority_support' => false,
                    'webhooks' => false,
                    'analytics' => false,
                    'hardware_locking' => false,
                    'offline_licenses' => false,
                    'multi_project' => false,
                ],
            ],
            'standard' => [
                'key' => 'standard',
                'label' => 'Standard',
                'prices' => self::prices(2490.0, 99.0, 119.0),
                'price_eur' => 99.0,
                'interval' => 'month',
                'included_active_licenses' => 1000,
                'extra_active_license_prices' => self::prices(4.0, 0.15, 0.15),
                'extra_active_license_price_eur' => 0.15,
                'hard_active_license_limit' => null,
                'analytics_level' => 'basic',
                'features' => [
                    'basic_api' => true,
                    'stripe_integration' => true,
                    'community_support' => false,
                    'email_support' => true,
                    'priority_support' => false,
                    'webhooks' => true,
                    'analytics' => true,
                    'hardware_locking' => true,
                    'offline_licenses' => false,
                    'multi_project' => false,
                ],
            ],
            'business' => [
                'key' => 'business',
                'label' => 'Business',
                'prices' => self::prices(4990.0, 199.0, 229.0),
                'price_eur' => 199.0,
                'interval' => 'month',
                'included_active_licenses' => 2500,
                'extra_active_license_prices' => self::prices(3.0, 0.10, 0.10),
                'extra_active_license_price_eur' => 0.10,
                'hard_active_license_limit' => null,
                'analytics_level' => 'advanced',
                'features' => [
                    'basic_api' => true,
                    'stripe_integration' => true,
                    'community_support' => false,
                    'email_support' => true,
                    'priority_support' => true,
                    'webhooks' => true,
                    'analytics' => true,
                    'hardware_locking' => true,
                    'offline_licenses' => true,
                    'multi_project' => true,
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function definition(string $planKey): array
    {
        $definitions = self::definitions();

        return $definitions[$planKey] ?? $definitions['free'];
    }

    public static function hasFeature(string $planKey, string $featureKey): bool
    {
        return (bool) (self::definition($planKey)['features'][$featureKey] ?? false);
    }

    public static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return in_array($currency, ['CZK', 'EUR', 'USD'], true) ? $currency : 'EUR';
    }

    public static function priceForCurrency(string $planKey, string $currency): float
    {
        $plan = self::definition($planKey);
        $currency = self::normalizeCurrency($currency);

        return (float) (($plan['prices'][$currency] ?? null) ?? ($plan['prices']['EUR'] ?? $plan['price_eur'] ?? 0.0));
    }

    public static function extraActiveLicensePriceForCurrency(string $planKey, string $currency): float
    {
        $plan = self::definition($planKey);
        $currency = self::normalizeCurrency($currency);

        return (float) (($plan['extra_active_license_prices'][$currency] ?? null) ?? ($plan['extra_active_license_prices']['EUR'] ?? $plan['extra_active_license_price_eur'] ?? 0.0));
    }

    /** @return string[] */
    public static function selectablePlanKeys(): array
    {
        return ['free', 'starter', 'standard', 'business', 'demo', 'internal'];
    }
}
