<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;
use App\Repositories\AppNotificationRepository;
use App\Repositories\AppProductRepository;
use App\Repositories\AppRepository;
use App\Repositories\AISettingsRepository;
use App\Repositories\GoogleAnalyticsRepository;
use App\Repositories\PolicyRepository;
use App\Repositories\ProductRepository;
use App\Repositories\TenantMembershipRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TenantSettingsRepository;
use App\Repositories\TenantUserRepository;
use App\Services\AppKeyService;
use App\Services\LicenseKeyGenerator;
use App\Support\Json;

if (App\Support\Env::get('APP_ENV') !== 'local') {
    throw new RuntimeException('Demo provisioning requires APP_ENV=local.');
}
$demoEmail = trim((string) App\Support\Env::get('DEMO_EMAIL', 'demo@example.invalid'));
$demoPassword = (string) App\Support\Env::get('DEMO_PASSWORD', '');
if (!filter_var($demoEmail, FILTER_VALIDATE_EMAIL) || !App\Services\CustomerAuthService::isPasswordAcceptable($demoPassword, $demoEmail)) {
    throw new RuntimeException('Set DEMO_EMAIL and a strong DEMO_PASSWORD before provisioning.');
}

$tenantRepo = new TenantRepository();
$tenantSettings = new TenantSettingsRepository();
$tenantUsers = new TenantUserRepository();
$memberships = new TenantMembershipRepository();
$apps = new AppRepository();
$products = new ProductRepository();
$bindings = new AppProductRepository();
$notifications = new AppNotificationRepository();
$analytics = new GoogleAnalyticsRepository();
$aiSettings = new AISettingsRepository();
$policies = new PolicyRepository();
$keyService = new AppKeyService();
$pdo = Database::pdo();

$tenant = $tenantRepo->findBySlug('simplykeys-demo');
if ($tenant === null) {
    $tenantId = $tenantRepo->create([
        'slug' => 'simplykeys-demo',
        'name' => 'SimplyKeys Demo',
        'status' => 'active',
        'plan_key' => 'demo',
        'timezone' => 'Europe/Prague',
        'default_currency' => 'EUR',
        'is_system' => false,
        'is_demo' => true,
    ]);
    $tenant = $tenantRepo->findById($tenantId);
} else {
    $tenantId = (int) $tenant['id'];
    $tenantRepo->update($tenantId, [
        'name' => 'SimplyKeys Demo',
        'status' => 'active',
        'plan_key' => 'demo',
        'timezone' => 'Europe/Prague',
        'default_currency' => 'EUR',
        'is_demo' => 1,
    ]);
    $tenant = $tenantRepo->findById($tenantId);
}

$tenantSettings->upsert($tenantId, 'enabled_currencies', 'EUR,CZK,USD');
$tenantSettings->upsert($tenantId, 'default_currency', 'EUR');

$demoUser = $tenantUsers->findByEmail($demoEmail);
if ($demoUser === null) {
    $demoUserId = $tenantUsers->create($demoEmail, password_hash($demoPassword, PASSWORD_DEFAULT), 'SimplyKeys Demo');
} else {
    $demoUserId = (int) $demoUser['id'];
    if (!password_verify($demoPassword, (string) $demoUser['password_hash'])) {
        throw new RuntimeException('Existing demo account password does not match; refusing to overwrite it.');
    }
}
$memberships->ensureActiveMembership($tenantId, $demoUserId, 'owner');

/** @return array<string,mixed> */
function demo_upsert_app(AppRepository $apps, int $tenantId, array $payload): array
{
    $existing = $apps->findBySlug((string) $payload['slug']);
    if ($existing === null) {
        $appId = $apps->create($payload + ['tenant_id' => $tenantId, 'is_active' => 1]);

        return $apps->findByIdForTenant($appId, $tenantId) ?? [];
    }

    $apps->update((int) $existing['id'], [
        'tenant_id' => $tenantId,
        'name' => $payload['name'],
        'description' => $payload['description'],
        'platform_type' => $payload['platform_type'],
        'base_url' => $payload['base_url'],
        'support_email' => $payload['support_email'],
        'notification_email' => $payload['notification_email'],
        'default_currency' => $payload['default_currency'],
        'enabled_currencies' => $payload['enabled_currencies'],
        'is_active' => 1,
    ]);

    return $apps->findByIdForTenant((int) $existing['id'], $tenantId) ?? [];
}

/** @return array<string,mixed> */
function demo_upsert_product(ProductRepository $products, int $tenantId, array $payload): array
{
    $existing = $products->findBySlugForTenant((string) $payload['slug'], $tenantId);
    if ($existing === null) {
        $productId = $products->create(
            (string) $payload['slug'],
            (string) $payload['name'],
            (string) $payload['description'],
            (int) ($payload['sort_order'] ?? 0),
            $tenantId
        );
        $existing = $products->findByIdForTenant($productId, $tenantId);
    } else {
        $products->update((int) $existing['id'], [
            'name' => $payload['name'],
            'description' => $payload['description'],
            'is_active' => 1,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);
        $existing = $products->findByIdForTenant((int) $existing['id'], $tenantId);
    }

    return $existing ?? [];
}

$appRows = [
    demo_upsert_app($apps, $tenantId, [
        'slug' => 'simplykeys-demo-desktop',
        'name' => 'SimplyKeys Desktop Demo',
        'description' => 'Demo desktop licensing project.',
        'platform_type' => 'desktop',
        'base_url' => 'https://demo.example.invalid/desktop',
        'support_email' => 'demo@example.invalid',
        'notification_email' => 'demo@example.invalid',
        'default_currency' => 'EUR',
        'enabled_currencies' => 'EUR,CZK,USD',
    ]),
    demo_upsert_app($apps, $tenantId, [
        'slug' => 'simplykeys-demo-web',
        'name' => 'SimplyKeys Web Demo',
        'description' => 'Demo web checkout project.',
        'platform_type' => 'web',
        'base_url' => 'https://demo.example.invalid/web',
        'support_email' => 'demo@example.invalid',
        'notification_email' => 'demo@example.invalid',
        'default_currency' => 'EUR',
        'enabled_currencies' => 'EUR,CZK,USD',
    ]),
    demo_upsert_app($apps, $tenantId, [
        'slug' => 'simplykeys-demo-unity',
        'name' => 'SimplyKeys Unity Demo',
        'description' => 'Demo Unity licensing project.',
        'platform_type' => 'unity',
        'base_url' => 'https://demo.example.invalid/unity',
        'support_email' => 'demo@example.invalid',
        'notification_email' => 'demo@example.invalid',
        'default_currency' => 'EUR',
        'enabled_currencies' => 'EUR,CZK,USD',
    ]),
];

foreach ($appRows as $app) {
    if ($app === []) {
        continue;
    }

    $notifications->upsertProfile((int) $app['id'], [
        'is_enabled' => true,
        'sender_name' => 'SimplyKeys Demo',
        'reply_to_email' => 'demo@example.invalid',
        'sales_email' => 'demo@example.invalid',
        'ops_email' => 'demo@example.invalid',
        'support_url' => 'https://example.invalid/demo-support',
        'brand_color' => '#1f7a8c',
    ]);

    $analytics->upsertForApp((int) $app['id'], [
        'is_enabled' => true,
        'measurement_id' => 'G-DEMO12345',
        'website_url' => (string) ($app['base_url'] ?? ''),
        'property_name' => (string) $app['name'] . ' Analytics',
        'admin_link' => 'https://analytics.google.com',
        'notes' => 'Demo integration, no live data.',
    ]);

    $keys = (new \App\Repositories\AppKeyRepository())->listForApp((int) $app['id']);
    if ($keys === []) {
        $keyService->createClientKey((int) $app['id'], 'Demo Client Key');
        $keyService->createServerSecret((int) $app['id'], 'Demo Server Key');
    }

    $notifications->upsertTemplateOverride(
        (int) $app['id'],
        'new_license',
        'Demo access for {product_name}',
        "Hello {customer_name},\n\nThis is a demo license email for {product_name}.\nYour license key is {license_key}.\n\nSimplyKeys Demo",
        true
    );
}

$productRows = [
    demo_upsert_product($products, $tenantId, [
        'slug' => 'desktop-pro',
        'name' => 'Desktop Pro',
        'description' => 'Lifetime desktop licence for the demo project.',
        'sort_order' => 10,
    ]),
    demo_upsert_product($products, $tenantId, [
        'slug' => 'web-business',
        'name' => 'Web Business',
        'description' => 'Subscription web licence for the demo project.',
        'sort_order' => 20,
    ]),
    demo_upsert_product($products, $tenantId, [
        'slug' => 'unity-toolkit',
        'name' => 'Unity Toolkit',
        'description' => 'Demo Unity toolkit licensing package.',
        'sort_order' => 30,
    ]),
];

$productBySlug = [];
foreach ($productRows as $product) {
    if ($product !== []) {
        $productBySlug[(string) $product['slug']] = $product;
    }
}

$appBySlug = [];
foreach ($appRows as $app) {
    if ($app !== []) {
        $appBySlug[(string) $app['slug']] = $app;
    }
}

$bindings->attach((int) $appBySlug['simplykeys-demo-desktop']['id'], (int) $productBySlug['desktop-pro']['id']);
$bindings->attach((int) $appBySlug['simplykeys-demo-web']['id'], (int) $productBySlug['web-business']['id']);
$bindings->attach((int) $appBySlug['simplykeys-demo-unity']['id'], (int) $productBySlug['unity-toolkit']['id']);

/** @param array<int,array<string,mixed>> $tariffs */
function demo_upsert_tariffs(ProductRepository $products, int $tenantId, int $productId, array $tariffs): void
{
    $existing = [];
    foreach ($products->getTariffsForTenant($productId, $tenantId) as $row) {
        $existing[(string) $row['license_type'] . ':' . (string) $row['currency']] = $row;
    }

    foreach ($tariffs as $sortOrder => $tariff) {
        $key = (string) $tariff['license_type'] . ':' . (string) $tariff['currency'];
        if (isset($existing[$key])) {
            $products->updateTariff((int) $existing[$key]['id'], [
                'stripe_price_id' => $tariff['stripe_price_id'],
                'price' => $tariff['price'],
                'is_recommended' => $tariff['is_recommended'] ? 1 : 0,
                'is_default' => $tariff['is_default'] ? 1 : 0,
                'is_active' => 1,
                'sort_order' => $sortOrder,
            ]);
            continue;
        }

        $products->createTariff(
            $productId,
            (string) $tariff['license_type'],
            (string) $tariff['stripe_price_id'],
            'license',
            null,
            (float) $tariff['price'],
            (string) $tariff['currency'],
            (bool) $tariff['is_recommended'],
            (bool) $tariff['is_default'],
            $sortOrder
        );
    }
}

demo_upsert_tariffs($products, $tenantId, (int) $productBySlug['desktop-pro']['id'], [
    ['license_type' => 'lifetime', 'currency' => 'EUR', 'price' => 29.99, 'stripe_price_id' => 'demo_price_desktop_eur', 'is_recommended' => true, 'is_default' => true],
    ['license_type' => 'lifetime', 'currency' => 'CZK', 'price' => 790.00, 'stripe_price_id' => 'demo_price_desktop_czk', 'is_recommended' => false, 'is_default' => false],
    ['license_type' => 'lifetime', 'currency' => 'USD', 'price' => 34.99, 'stripe_price_id' => 'demo_price_desktop_usd', 'is_recommended' => false, 'is_default' => false],
]);
demo_upsert_tariffs($products, $tenantId, (int) $productBySlug['web-business']['id'], [
    ['license_type' => 'monthly', 'currency' => 'EUR', 'price' => 19.99, 'stripe_price_id' => 'demo_price_web_monthly_eur', 'is_recommended' => true, 'is_default' => true],
    ['license_type' => 'annual', 'currency' => 'EUR', 'price' => 199.00, 'stripe_price_id' => 'demo_price_web_annual_eur', 'is_recommended' => false, 'is_default' => false],
]);
demo_upsert_tariffs($products, $tenantId, (int) $productBySlug['unity-toolkit']['id'], [
    ['license_type' => 'annual', 'currency' => 'EUR', 'price' => 149.00, 'stripe_price_id' => 'demo_price_unity_annual_eur', 'is_recommended' => true, 'is_default' => true],
    ['license_type' => 'annual', 'currency' => 'USD', 'price' => 169.00, 'stripe_price_id' => 'demo_price_unity_annual_usd', 'is_recommended' => false, 'is_default' => false],
]);

$policies->upsertAppPolicy((int) $appBySlug['simplykeys-demo-desktop']['id'], 'max_activations', 3, true);
$policies->upsertAppPolicy((int) $appBySlug['simplykeys-demo-desktop']['id'], 'allow_auto_activation', true, true);
$policies->upsertProductPolicy((int) $productBySlug['desktop-pro']['id'], 'enabled_features', ['sync', 'analytics', 'priority_support'], true);

$desktopTariffs = $products->getTariffsForTenant((int) $productBySlug['desktop-pro']['id'], $tenantId);
foreach ($desktopTariffs as $tariff) {
    if ((string) $tariff['currency'] === 'EUR') {
        $policies->upsertTariffPolicy((int) $tariff['id'], 'jwt_ttl_seconds', 86400, true);
        break;
    }
}

$aiSettings->upsertByModuleKey('ai_insights', [
    'tenant_id' => $tenantId,
    'is_enabled' => true,
    'provider_name' => 'demo',
    'model_name' => 'demo-insights',
    'api_key' => '',
    'daily_request_limit' => 25,
    'store_prompts' => false,
    'redact_sensitive_data' => true,
    'enabled_app_ids' => array_values(array_map(static fn (array $app): int => (int) $app['id'], $appRows)),
]);
$aiSettings->upsertByModuleKey('ai_assistant', [
    'tenant_id' => $tenantId,
    'is_enabled' => true,
    'provider_name' => 'demo',
    'model_name' => 'demo-assistant',
    'api_key' => '',
    'daily_request_limit' => 25,
    'store_prompts' => false,
    'redact_sensitive_data' => true,
    'enabled_app_ids' => array_values(array_map(static fn (array $app): int => (int) $app['id'], $appRows)),
]);

$licenseGenerator = new LicenseKeyGenerator();
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM licenses WHERE tenant_id = :tenant_id');
$countStmt->execute(['tenant_id' => $tenantId]);
$existingLicenseCount = (int) $countStmt->fetchColumn();
if ($existingLicenseCount === 0) {
    $licenseStmt = $pdo->prepare(
        'INSERT INTO licenses
         (tenant_id, app_id, license_key, license_type, product_slug, product_name, customer_email, customer_name, price, currency, status, max_activations, current_activations, issued_at, last_verified_at)
         VALUES
         (:tenant_id, :app_id, :license_key, :license_type, :product_slug, :product_name, :customer_email, :customer_name, :price, :currency, :status, :max_activations, :current_activations, NOW(), NOW())'
    );

    $demoLicenses = [
        [
            'app' => $appBySlug['simplykeys-demo-desktop'],
            'product' => $productBySlug['desktop-pro'],
            'license_type' => 'lifetime',
            'customer_email' => 'alice.demo@example.com',
            'customer_name' => 'Alice Demo',
            'price' => 29.99,
            'currency' => 'EUR',
            'status' => 'active',
            'max_activations' => 3,
            'current_activations' => 1,
        ],
        [
            'app' => $appBySlug['simplykeys-demo-web'],
            'product' => $productBySlug['web-business'],
            'license_type' => 'annual',
            'customer_email' => 'bob.demo@example.com',
            'customer_name' => 'Bob Demo',
            'price' => 199.00,
            'currency' => 'EUR',
            'status' => 'active',
            'max_activations' => 5,
            'current_activations' => 2,
        ],
        [
            'app' => $appBySlug['simplykeys-demo-unity'],
            'product' => $productBySlug['unity-toolkit'],
            'license_type' => 'annual',
            'customer_email' => 'charlie.demo@example.com',
            'customer_name' => 'Charlie Demo',
            'price' => 169.00,
            'currency' => 'USD',
            'status' => 'pending',
            'max_activations' => 2,
            'current_activations' => 0,
        ],
    ];

    foreach ($demoLicenses as $license) {
        $key = $licenseGenerator->generate((string) $license['license_type']);
        $licenseStmt->execute([
            'tenant_id' => $tenantId,
            'app_id' => (int) $license['app']['id'],
            'license_key' => $key,
            'license_type' => $license['license_type'],
            'product_slug' => (string) $license['product']['slug'],
            'product_name' => (string) $license['product']['name'],
            'customer_email' => $license['customer_email'],
            'customer_name' => $license['customer_name'],
            'price' => $license['price'],
            'currency' => $license['currency'],
            'status' => $license['status'],
            'max_activations' => $license['max_activations'],
            'current_activations' => $license['current_activations'],
        ]);

        $licenseId = (int) $pdo->lastInsertId();
        for ($activationIndex = 0; $activationIndex < (int) $license['current_activations']; $activationIndex++) {
            $pdo->prepare(
                'INSERT INTO license_activations
                 (license_id, domain, installation_hash, php_version, cms_version, database_type, is_active, activated_at, last_ping_at)
                 VALUES
                 (:license_id, :domain, :installation_hash, :php_version, :cms_version, :database_type, 1, NOW(), NOW())'
            )->execute([
                'license_id' => $licenseId,
                'domain' => 'demo-client',
                'installation_hash' => 'demo-installation-' . $licenseId . '-' . $activationIndex,
                'php_version' => '8.2',
                'cms_version' => '1.0.0',
                'database_type' => 'mysql',
            ]);
        }

        $pdo->prepare(
            'INSERT INTO license_verifications (license_id, verified_at, ip_address, status, response_time_ms)
             VALUES (:license_id, NOW(), :ip_address, :status, :response_time_ms)'
        )->execute([
            'license_id' => $licenseId,
            'ip_address' => '127.0.0.1',
            'status' => $license['status'] === 'pending' ? 'invalid' : 'valid',
            'response_time_ms' => 42,
        ]);
    }
}

$aiInsightRunsStmt = $pdo->prepare('SELECT COUNT(*) FROM ai_insight_runs WHERE tenant_id = :tenant_id');
$aiInsightRunsStmt->execute(['tenant_id' => $tenantId]);
if ((int) $aiInsightRunsStmt->fetchColumn() === 0) {
    $pdo->prepare(
        'INSERT INTO ai_insight_runs (tenant_id, app_id, insight_type, input_summary, output_text, status, created_at)
         VALUES (:tenant_id, :app_id, :insight_type, :input_summary, :output_text, :status, NOW())'
    )->execute([
        'tenant_id' => $tenantId,
        'app_id' => (int) $appBySlug['simplykeys-demo-web']['id'],
        'insight_type' => 'ops_summary',
        'input_summary' => 'Demo operational summary input.',
        'output_text' => 'Demo insight: webhook delivery is stable, with one failed retry prepared for presentation.',
        'status' => 'completed',
    ]);
}

$aiAssistantRunsStmt = $pdo->prepare('SELECT COUNT(*) FROM ai_assistant_runs WHERE tenant_id = :tenant_id');
$aiAssistantRunsStmt->execute(['tenant_id' => $tenantId]);
if ((int) $aiAssistantRunsStmt->fetchColumn() === 0) {
    $pdo->prepare(
        'INSERT INTO ai_assistant_runs (tenant_id, app_id, task_type, input_text, output_text, status, created_at)
         VALUES (:tenant_id, :app_id, :task_type, :input_text, :output_text, :status, NOW())'
    )->execute([
        'tenant_id' => $tenantId,
        'app_id' => (int) $appBySlug['simplykeys-demo-desktop']['id'],
        'task_type' => 'support_reply',
        'input_text' => 'How do I activate my license?',
        'output_text' => 'Demo draft: open the desktop app, paste the license key, and confirm activation on the current device.',
        'status' => 'completed',
    ]);
}

$webhookCountStmt = $pdo->prepare('SELECT COUNT(*) FROM webhook_logs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, "$.metadata.app_slug")) LIKE "simplykeys-demo-%"');
$webhookCountStmt->execute();
if ((int) $webhookCountStmt->fetchColumn() === 0) {
    $webhookStmt = $pdo->prepare(
        'INSERT INTO webhook_logs (event_type, payload, signature, status, error_message, processed_at)
         VALUES (:event_type, :payload, :signature, :status, :error_message, NOW())'
    );
    foreach ([
        ['event_type' => 'checkout.session.completed', 'app_slug' => 'simplykeys-demo-web', 'status' => 'success', 'error_message' => null],
        ['event_type' => 'invoice.paid', 'app_slug' => 'simplykeys-demo-unity', 'status' => 'success', 'error_message' => null],
        ['event_type' => 'customer.subscription.updated', 'app_slug' => 'simplykeys-demo-web', 'status' => 'failed', 'error_message' => 'Demo webhook failure for preview purposes.'],
    ] as $row) {
        $webhookStmt->execute([
            'event_type' => $row['event_type'],
            'payload' => Json::encode([
                'id' => 'evt_demo_' . substr(md5($row['event_type'] . $row['app_slug']), 0, 12),
                'metadata' => [
                    'app_slug' => $row['app_slug'],
                ],
            ]),
            'signature' => 'demo-signature',
            'status' => $row['status'],
            'error_message' => $row['error_message'],
        ]);
    }
}

fwrite(STDOUT, "Demo tenant prepared: simplykeys-demo\n");
fwrite(STDOUT, "Demo login: {$demoEmail} (password supplied via environment)\n");
