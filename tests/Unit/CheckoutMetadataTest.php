<?php

declare(strict_types=1);

use App\Support\CheckoutMetadata;
use PHPUnit\Framework\TestCase;

final class CheckoutMetadataTest extends TestCase
{
    public function testClientCannotOverrideValidatedCheckoutFields(): void
    {
        $trusted = [
            'metadata[license_type]' => 'monthly',
            'metadata[app_slug]' => 'original',
            'metadata[tenant_id]' => '1',
            'metadata[purchase_kind]' => 'license',
            'metadata[target_plan_key]' => '',
            'metadata[product_slug]' => 'basic',
        ];
        $result = CheckoutMetadata::append($trusted, [
            'license_type' => 'lifetime', 'app_slug' => 'victim', 'tenant_id' => '2',
            'purchase_kind' => 'tenant_plan', 'target_plan_key' => 'enterprise',
            'product_slug' => 'premium', 'campaign' => 'newsletter',
            'nested][key' => 'bad', 'invalid' => ['nested'],
        ]);
        self::assertSame($trusted + ['metadata[campaign]' => 'newsletter'], $result);
    }
}
