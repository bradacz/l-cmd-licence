<?php

declare(strict_types=1);

namespace App\Support;

final class PolicyCatalog
{
    /** @return array<string,array{label:string,description:string,placeholder:string}> */
    public static function definitions(): array
    {
        return [
            'max_activations' => [
                'label' => 'Max Activations',
                'description' => 'Maximum allowed active installations.',
                'placeholder' => '3',
            ],
            'jwt_ttl_seconds' => [
                'label' => 'JWT TTL Seconds',
                'description' => 'Token lifetime for verify responses.',
                'placeholder' => '86400',
            ],
            'grace_period_days' => [
                'label' => 'Grace Period Days',
                'description' => 'Optional grace period after expiry.',
                'placeholder' => '7',
            ],
            'allow_auto_activation' => [
                'label' => 'Allow Auto Activation',
                'description' => 'Boolean flag for automatic activation during verify.',
                'placeholder' => 'true',
            ],
            'enabled_features' => [
                'label' => 'Enabled Features',
                'description' => 'JSON array of enabled feature flags.',
                'placeholder' => '["sync","analytics"]',
            ],
            'allowed_platforms' => [
                'label' => 'Allowed Platforms',
                'description' => 'JSON array of allowed runtime platforms.',
                'placeholder' => '["desktop","web"]',
            ],
            'mail_template_set' => [
                'label' => 'Mail Template Set',
                'description' => 'Optional mail template profile identifier.',
                'placeholder' => '"default"',
            ],
        ];
    }

    public static function has(string $policyKey): bool
    {
        return array_key_exists($policyKey, self::definitions());
    }
}
