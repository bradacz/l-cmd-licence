<?php

declare(strict_types=1);

namespace App\Http;

use App\Database;
use App\Repositories\AppKeyRepository;
use App\Repositories\AppNotificationRepository;
use App\Repositories\AppProductRepository;
use App\Repositories\AppRepository;
use App\Repositories\AIAssistantRepository;
use App\Repositories\AIInsightsRepository;
use App\Repositories\AISettingsRepository;
use App\Repositories\ActivationRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\GoogleAnalyticsRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\PolicyRepository;
use App\Repositories\TenantAccessKeyRepository;
use App\Repositories\TenantAnalyticsRepository;
use App\Repositories\TenantMembershipRepository;
use App\Repositories\TenantPasswordResetTokenRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TenantSettingsRepository;
use App\Repositories\TenantUserRepository;
use App\Repositories\ProductRepository;
use App\Repositories\VerificationRepository;
use App\Repositories\WebhookLogRepository;
use App\Services\AppKeyService;
use App\Services\AIAssistantService;
use App\Services\AIInsightsService;
use App\Services\CustomerAuthService;
use App\Services\CustomerSmtpService;
use App\Services\CustomerSessionService;
use App\Services\CustomerStripeService;
use App\Services\CsrfService;
use App\Services\DemoTenantService;
use App\Services\GoogleAnalyticsService;
use App\Services\OfflineTokenSigner;
use App\Services\RateLimiter;
use App\Services\TenantBillingService;
use App\Support\CurrencyCatalog;
use App\Support\AppCheckoutUrlConfig;
use App\Support\IntegrationGuideBuilder;
use App\Support\PolicyCatalog;
use App\Support\TenantPlanCatalog;

final class CustomerApi
{
    /** @param array<string,string> $routeParams */
    public static function csrf(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);

        Response::json([
            'csrf_token' => CsrfService::token(),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function activate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request, 'customer_activate', 10)) {
            return;
        }

        $email = mb_strtolower(trim((string) ($request->json['email'] ?? '')));
        $accessKey = trim((string) ($request->json['activation_key'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $accessKey === '') {
            Response::json(['error' => 'Invalid activation payload'], 422);
            return;
        }

        try {
            $result = (new CustomerAuthService())->beginActivation($email, $accessKey, $request->ip, $request->userAgent);
        } catch (\RuntimeException | \DomainException $e) {
            Response::json(['error' => ClientError::message($e)], 422);
            return;
        }

        Response::json([
            'success' => true,
            'activation' => $result,
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function setPassword(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request, 'customer_set_password', 10)) {
            return;
        }

        $password = (string) ($request->json['password'] ?? '');
        $passwordConfirmation = (string) ($request->json['password_confirmation'] ?? '');
        $activationToken = trim((string) ($request->json['activation_token'] ?? ''));
        if ($password === '' || $password !== $passwordConfirmation) {
            Response::json(['error' => 'Password confirmation does not match'], 422);
            return;
        }

        try {
            (new CustomerAuthService())->completeActivation(
                $password,
                trim((string) ($request->json['name'] ?? '')) ?: null,
                $activationToken !== '' ? $activationToken : null
            );
        } catch (\RuntimeException | \DomainException $e) {
            Response::json(['error' => ClientError::message($e)], 422);
            return;
        }

        Response::json([
            'success' => true,
            'me' => self::mePayload((new CustomerSessionService())->current()),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function register(Request $request, array $routeParams): void
    {
        unset($routeParams);

        $email = mb_strtolower(trim((string) ($request->json['email'] ?? '')));
        $password = (string) ($request->json['password'] ?? '');
        $name = trim((string) ($request->json['name'] ?? '')) ?: null;
        $tenantName = trim((string) ($request->json['tenant_name'] ?? ''));
        $tenantSlug = trim((string) ($request->json['tenant_slug'] ?? ''));

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !CustomerAuthService::isPasswordAcceptable($password, $email)
            || $tenantName === ''
            || !preg_match('/^[a-z0-9-]+$/', $tenantSlug)
        ) {
            Response::json(['error' => 'Invalid registration payload'], 422);
            return;
        }

        $users = new TenantUserRepository();
        $tenants = new TenantRepository();
        if ($users->findByEmail($email) !== null || $tenants->findBySlug($tenantSlug) !== null) {
            Response::json(['error' => 'Account or tenant already exists'], 409);
            return;
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();

            $tenantId = $tenants->create([
                'slug' => $tenantSlug,
                'name' => $tenantName,
                'status' => 'active',
                'plan_key' => 'free',
                'timezone' => 'Europe/Prague',
                'default_currency' => 'EUR',
                'is_system' => false,
            ]);

            $userId = $users->create($email, password_hash($password, PASSWORD_DEFAULT), $name);
            (new TenantMembershipRepository())->create($tenantId, $userId, 'owner');

            $settings = new TenantSettingsRepository();
            $settings->upsert($tenantId, 'enabled_currencies', 'EUR,CZK,USD');
            $settings->upsert($tenantId, 'default_currency', 'EUR');

            $pdo->commit();

            $session = new CustomerSessionService();
            $session->login($userId);

            Response::json([
                'success' => true,
                'tenant' => [
                    'id' => $tenantId,
                    'slug' => $tenantSlug,
                    'name' => $tenantName,
                ],
            ], 201);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[customer.register] ' . $e->getMessage());
            Response::json(['error' => 'Registration failed'], 500);
        }
    }

    /** @param array<string,string> $routeParams */
    public static function login(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request, 'customer_login', 10)) {
            return;
        }

        $email = mb_strtolower(trim((string) ($request->json['email'] ?? '')));
        $password = (string) ($request->json['password'] ?? '');
        $tenantId = isset($request->json['tenant_id']) ? (int) $request->json['tenant_id'] : null;
        $user = (new TenantUserRepository())->findByEmail($email);

        if ($user === null || empty($user['is_active']) || !password_verify($password, (string) $user['password_hash'])) {
            Response::json(['error' => 'Invalid credentials'], 401);
            return;
        }

        $session = new CustomerSessionService();
        try {
            $session->login((int) $user['id'], $tenantId);
        } catch (\RuntimeException | \DomainException $e) {
            Response::json(['error' => ClientError::message($e)], 422);
            return;
        }

        Response::json([
            'success' => true,
            'me' => self::mePayload($session->current()),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function logout(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        (new CustomerSessionService())->logout();

        Response::json(['success' => true]);
    }

    /** @param array<string,string> $routeParams */
    public static function forgotPassword(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request, 'customer_forgot_password', 5)) {
            return;
        }

        $email = mb_strtolower(trim((string) ($request->json['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['error' => 'Valid email is required'], 422);
            return;
        }

        (new CustomerAuthService())->createPasswordReset($email);
        Response::json(['success' => true]);
    }

    /** @param array<string,string> $routeParams */
    public static function resetPassword(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request, 'customer_reset_password', 5)) {
            return;
        }

        $token = trim((string) ($request->json['reset_token'] ?? ''));
        $password = (string) ($request->json['password'] ?? '');
        $passwordConfirmation = (string) ($request->json['password_confirmation'] ?? '');
        if ($token === '' || $password === '' || $password !== $passwordConfirmation) {
            Response::json(['error' => 'Invalid reset payload'], 422);
            return;
        }

        try {
            (new CustomerAuthService())->resetPassword($token, $password);
        } catch (\RuntimeException | \DomainException $e) {
            Response::json(['error' => ClientError::message($e)], 422);
            return;
        }

        Response::json(['success' => true]);
    }

    /** @param array<string,string> $routeParams */
    public static function memberships(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);

        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        Response::json([
            'memberships' => self::membershipsPayload((array) ($current['memberships'] ?? [])),
            'active_tenant_id' => (int) $current['tenant_id'],
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function switchTenant(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request, 'customer_switch_tenant', 30)) {
            return;
        }

        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $tenantId = (int) ($request->json['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            Response::json(['error' => 'tenant_id is required'], 422);
            return;
        }

        $session = new CustomerSessionService();
        try {
            $session->switchTenant((int) $current['tenant_user_id'], $tenantId);
        } catch (\RuntimeException | \DomainException $e) {
            Response::json(['error' => ClientError::message($e)], 422);
            return;
        }

        Response::json([
            'success' => true,
            'me' => self::mePayload($session->current()),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function me(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);

        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        Response::json(['me' => self::mePayload($current)]);
    }

    /** @param array<string,string> $routeParams */
    public static function tenantProfile(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);

        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $tenant = (new TenantRepository())->findById((int) $current['tenant_id']);
        $settings = (new TenantSettingsRepository())->allForTenant((int) $current['tenant_id']);

        Response::json([
            'tenant' => self::tenantPayload($tenant, $settings),
            'billing' => (new TenantBillingService())->summaryForTenant((int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function tenantProfileUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);

        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $tenantId = (int) $current['tenant_id'];
        $tenantName = trim((string) ($request->json['name'] ?? ''));
        $timezone = trim((string) ($request->json['timezone'] ?? 'Europe/Prague'));
        $defaultCurrency = CurrencyCatalog::normalize((string) ($request->json['default_currency'] ?? 'EUR')) ?? 'EUR';
        $enabledCurrencies = array_values(array_filter(array_map(
            static fn (mixed $value): ?string => CurrencyCatalog::normalize((string) $value),
            (array) ($request->json['enabled_currencies'] ?? ['EUR', 'CZK', 'USD'])
        )));

        if ($tenantName === '') {
            Response::json(['error' => 'Tenant name is required'], 422);
            return;
        }
        if (!in_array($defaultCurrency, $enabledCurrencies, true)) {
            $enabledCurrencies[] = $defaultCurrency;
        }

        (new TenantRepository())->update($tenantId, [
            'name' => $tenantName,
            'timezone' => $timezone,
            'default_currency' => $defaultCurrency,
        ]);

        $settings = new TenantSettingsRepository();
        $settings->upsert($tenantId, 'enabled_currencies', implode(',', $enabledCurrencies));
        $settings->upsert($tenantId, 'default_currency', $defaultCurrency);

        $tenant = (new TenantRepository())->findById($tenantId);

        Response::json([
            'success' => true,
            'tenant' => self::tenantPayload($tenant, $settings->allForTenant($tenantId)),
            'billing' => (new TenantBillingService())->summaryForTenant($tenantId),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function billingPlan(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);

        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        Response::json([
            'billing' => (new TenantBillingService())->summaryForTenant((int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function analyticsSummary(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);

        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requirePlanFeature((int) $current['tenant_id'], 'analytics')) {
            return;
        }

        $tenantId = (int) $current['tenant_id'];
        Response::json([
            'analytics' => (new TenantAnalyticsRepository())->basicSummary($tenantId),
            'billing' => (new TenantBillingService())->summaryForTenant($tenantId),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function analyticsAdvanced(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);

        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireAnalyticsLevel((int) $current['tenant_id'], 'advanced')) {
            return;
        }

        $tenantId = (int) $current['tenant_id'];
        Response::json([
            'analytics' => (new TenantAnalyticsRepository())->advancedSummary($tenantId),
            'billing' => (new TenantBillingService())->summaryForTenant($tenantId),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appsList(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $apps = (new AppRepository())->listAllForTenant((int) $current['tenant_id']);
        Response::json(['apps' => $apps]);
    }

    /** @param array<string,string> $routeParams */
    public static function appsCreate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        try {
            (new TenantBillingService())->assertCanCreateProject((int) $current['tenant_id']);
        } catch (\RuntimeException | \DomainException $e) {
            Response::json([
                'error' => ClientError::message($e),
                'billing' => (new TenantBillingService())->summaryForTenant((int) $current['tenant_id']),
            ], 403);
            return;
        }

        $slug = trim((string) ($request->json['slug'] ?? ''));
        $name = trim((string) ($request->json['name'] ?? ''));
        if ($slug === '' || $name === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            Response::json(['error' => 'Invalid app payload'], 422);
            return;
        }

        $repo = new AppRepository();
        if ($repo->findBySlug($slug) !== null) {
            Response::json(['error' => 'App slug already exists'], 409);
            return;
        }

        $checkoutConfig = AppCheckoutUrlConfig::normalize(
            (string) ($request->json['public_base_url'] ?? ''),
            (string) ($request->json['checkout_success_url'] ?? ''),
            (string) ($request->json['checkout_cancel_url'] ?? '')
        );
        $checkoutError = AppCheckoutUrlConfig::validate(
            $checkoutConfig['checkout_success_url'],
            $checkoutConfig['checkout_cancel_url']
        );
        if ($checkoutError !== null) {
            Response::json(['error' => $checkoutError], 422);
            return;
        }

        $appId = $repo->create([
            'tenant_id' => (int) $current['tenant_id'],
            'slug' => $slug,
            'name' => $name,
            'description' => trim((string) ($request->json['description'] ?? '')) ?: null,
            'platform_type' => trim((string) ($request->json['platform_type'] ?? '')) ?: null,
            'base_url' => trim((string) ($request->json['base_url'] ?? '')) ?: null,
            'public_base_url' => $checkoutConfig['public_base_url'],
            'checkout_success_url' => $checkoutConfig['checkout_success_url'],
            'checkout_cancel_url' => $checkoutConfig['checkout_cancel_url'],
            'support_email' => trim((string) ($request->json['support_email'] ?? '')) ?: null,
            'notification_email' => trim((string) ($request->json['notification_email'] ?? '')) ?: null,
            'is_active' => (int) ($request->json['is_active'] ?? 1) === 1,
        ]);

        Response::json([
            'success' => true,
            'app' => $repo->findByIdForTenant($appId, (int) $current['tenant_id']),
        ], 201);
    }

    /** @param array<string,string> $routeParams */
    public static function appsDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        Response::json([
            'app' => $app,
            'offline_verification' => [
                'public_key_url' => self::offlinePublicKeyUrl((int) $app['id']),
            ],
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appsUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $repo = new AppRepository();
        $app = $repo->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $slug = trim((string) ($request->json['slug'] ?? (string) $app['slug']));
        if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            Response::json(['error' => 'Invalid app payload'], 422);
            return;
        }
        $existing = $repo->findBySlug($slug);
        if ($existing !== null && (int) $existing['id'] !== $appId) {
            Response::json(['error' => 'App slug already exists'], 409);
            return;
        }

        $checkoutConfig = AppCheckoutUrlConfig::normalize(
            (string) ($request->json['public_base_url'] ?? (string) ($app['public_base_url'] ?? '')),
            (string) ($request->json['checkout_success_url'] ?? (string) ($app['checkout_success_url'] ?? '')),
            (string) ($request->json['checkout_cancel_url'] ?? (string) ($app['checkout_cancel_url'] ?? ''))
        );
        $checkoutError = AppCheckoutUrlConfig::validate(
            $checkoutConfig['checkout_success_url'],
            $checkoutConfig['checkout_cancel_url']
        );
        if ($checkoutError !== null) {
            Response::json(['error' => $checkoutError], 422);
            return;
        }

        $repo->update($appId, [
            'name' => trim((string) ($request->json['name'] ?? (string) $app['name'])),
            'slug' => $slug,
            'description' => trim((string) ($request->json['description'] ?? (string) ($app['description'] ?? ''))) ?: null,
            'platform_type' => trim((string) ($request->json['platform_type'] ?? (string) ($app['platform_type'] ?? ''))) ?: null,
            'base_url' => trim((string) ($request->json['base_url'] ?? (string) ($app['base_url'] ?? ''))) ?: null,
            'public_base_url' => $checkoutConfig['public_base_url'],
            'checkout_success_url' => $checkoutConfig['checkout_success_url'],
            'checkout_cancel_url' => $checkoutConfig['checkout_cancel_url'],
            'support_email' => trim((string) ($request->json['support_email'] ?? (string) ($app['support_email'] ?? ''))) ?: null,
            'notification_email' => trim((string) ($request->json['notification_email'] ?? (string) ($app['notification_email'] ?? ''))) ?: null,
            'is_active' => (int) ($request->json['is_active'] ?? (int) ($app['is_active'] ?? 1)),
        ]);

        Response::json([
            'success' => true,
            'app' => $repo->findByIdForTenant($appId, (int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appsActivate(Request $request, array $routeParams): void
    {
        unset($request);
        self::setAppActiveState($routeParams, true);
    }

    /** @param array<string,string> $routeParams */
    public static function appsDeactivate(Request $request, array $routeParams): void
    {
        unset($request);
        self::setAppActiveState($routeParams, false);
    }

    /** @param array<string,string> $routeParams */
    public static function appKeysList(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $app = (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        Response::json(['keys' => (new AppKeyRepository())->listForApp($appId)]);
    }

    /** @param array<string,string> $routeParams */
    public static function appKeysCreate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $app = (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $keyName = trim((string) ($request->json['key_name'] ?? ''));
        $keyType = trim((string) ($request->json['key_type'] ?? 'client_public'));
        if ($keyName === '' || !in_array($keyType, ['client_public', 'server_secret'], true)) {
            Response::json(['error' => 'Invalid key payload'], 422);
            return;
        }

        $service = new AppKeyService();
        $generated = $keyType === 'server_secret'
            ? $service->createServerSecret($appId, $keyName)
            : $service->createClientKey($appId, $keyName);

        Response::json([
            'success' => true,
            'key' => $generated,
        ], 201);
    }

    /** @param array<string,string> $routeParams */
    public static function appKeyRotate(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $keyId = (int) ($routeParams['keyId'] ?? 0);
        $app = (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $keys = new AppKeyRepository();
        $existing = $keys->findByIdForApp($keyId, $appId);
        if ($existing === null) {
            Response::json(['error' => 'Key not found'], 404);
            return;
        }

        $rotated = (new AppKeyService())->rotate($existing);
        Response::json([
            'success' => true,
            'key' => $rotated,
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appKeyDeactivate(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $keyId = (int) ($routeParams['keyId'] ?? 0);
        $app = (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $keys = new AppKeyRepository();
        $key = $keys->findByIdForApp($keyId, $appId);
        if ($key === null) {
            Response::json(['error' => 'Key not found'], 404);
            return;
        }

        $keys->deactivate($keyId);
        Response::json([
            'success' => true,
            'key' => $keys->findByIdForApp($keyId, $appId),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appProductsDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $app = (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $products = (new ProductRepository())->listAllForTenant((int) $current['tenant_id']);
        $assignedRows = (new AppProductRepository())->listForApp($appId);
        $assignedMap = [];
        foreach ($assignedRows as $row) {
            $assignedMap[(int) $row['id']] = (int) ($row['binding_active'] ?? 0) === 1;
        }

        $payload = [];
        foreach ($products as $product) {
            $productId = (int) $product['id'];
            $payload[] = [
                'id' => $productId,
                'slug' => (string) $product['slug'],
                'name' => (string) $product['name'],
                'description' => (string) ($product['description'] ?? ''),
                'is_active' => (int) ($product['is_active'] ?? 1) === 1,
                'is_assigned' => (bool) ($assignedMap[$productId] ?? false),
            ];
        }

        Response::json([
            'app' => self::appSummary($app),
            'products' => $payload,
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appProductsUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $app = (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $tenantProducts = (new ProductRepository())->listAllForTenant((int) $current['tenant_id']);
        $tenantProductIds = array_map(static fn (array $row): int => (int) $row['id'], $tenantProducts);
        $selectedIds = array_values(array_unique(array_map('intval', (array) ($request->json['product_ids'] ?? []))));
        foreach ($selectedIds as $productId) {
            if (!in_array($productId, $tenantProductIds, true)) {
                Response::json(['error' => 'Invalid product assignment payload'], 422);
                return;
            }
        }

        $bindings = new AppProductRepository();
        foreach ($tenantProductIds as $productId) {
            if (in_array($productId, $selectedIds, true)) {
                $bindings->attach($appId, $productId);
            } else {
                $bindings->detach($appId, $productId);
            }
        }

        self::appProductsDetail($request, ['id' => (string) $appId]);
    }

    /** @param array<string,string> $routeParams */
    public static function productsList(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $products = (new ProductRepository())->listAllForTenant((int) $current['tenant_id']);
        Response::json(['products' => $products]);
    }

    /** @param array<string,string> $routeParams */
    public static function productsCreate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $slug = trim((string) ($request->json['slug'] ?? ''));
        $name = trim((string) ($request->json['name'] ?? ''));
        if ($slug === '' || $name === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            Response::json(['error' => 'Invalid product payload'], 422);
            return;
        }

        $repo = new ProductRepository();
        if ($repo->findBySlugForTenant($slug, (int) $current['tenant_id']) !== null) {
            Response::json(['error' => 'Product slug already exists in this tenant'], 409);
            return;
        }

        $productId = $repo->create(
            $slug,
            $name,
            trim((string) ($request->json['description'] ?? '')) ?: null,
            (int) ($request->json['sort_order'] ?? 0),
            (int) $current['tenant_id']
        );

        Response::json([
            'success' => true,
            'product' => $repo->findByIdForTenant($productId, (int) $current['tenant_id']),
        ], 201);
    }

    /** @param array<string,string> $routeParams */
    public static function productsDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $product = (new ProductRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($product === null) {
            Response::json(['error' => 'Product not found'], 404);
            return;
        }

        Response::json([
            'product' => $product,
            'tariffs' => (new ProductRepository())->getTariffsForTenant((int) $product['id'], (int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function productsUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $productId = (int) ($routeParams['id'] ?? 0);
        $repo = new ProductRepository();
        $product = $repo->findByIdForTenant($productId, (int) $current['tenant_id']);
        if ($product === null) {
            Response::json(['error' => 'Product not found'], 404);
            return;
        }

        $repo->update($productId, [
            'name' => trim((string) ($request->json['name'] ?? (string) $product['name'])),
            'description' => trim((string) ($request->json['description'] ?? (string) ($product['description'] ?? ''))) ?: null,
            'is_active' => (int) ($request->json['is_active'] ?? (int) ($product['is_active'] ?? 1)),
            'sort_order' => (int) ($request->json['sort_order'] ?? (int) ($product['sort_order'] ?? 0)),
        ]);

        Response::json([
            'success' => true,
            'product' => $repo->findByIdForTenant($productId, (int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function productTariffsList(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $product = (new ProductRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($product === null) {
            Response::json(['error' => 'Product not found'], 404);
            return;
        }

        Response::json([
            'product' => $product,
            'tariffs' => (new ProductRepository())->getTariffsForTenant((int) $product['id'], (int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function productTariffsCreate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $productId = (int) ($routeParams['id'] ?? 0);
        $repo = new ProductRepository();
        $product = $repo->findByIdForTenant($productId, (int) $current['tenant_id']);
        if ($product === null) {
            Response::json(['error' => 'Product not found'], 404);
            return;
        }

        $licenseType = trim((string) ($request->json['license_type'] ?? ''));
        $stripePriceId = trim((string) ($request->json['stripe_price_id'] ?? ''));
        $purchaseKind = trim((string) ($request->json['purchase_kind'] ?? 'license'));
        $targetPlanKey = trim((string) ($request->json['target_plan_key'] ?? ''));
        $price = (float) ($request->json['price'] ?? 0);
        $currency = CurrencyCatalog::normalize((string) ($request->json['currency'] ?? ''));
        if (!in_array($licenseType, ['monthly', 'annual', 'lifetime'], true)
            || !in_array($purchaseKind, ['license', 'tenant_plan'], true)
            || $stripePriceId === ''
            || $price <= 0
            || $currency === null
        ) {
            Response::json(['error' => 'Invalid tariff payload'], 422);
            return;
        }
        if ($purchaseKind === 'tenant_plan' && $targetPlanKey === '') {
            Response::json(['error' => 'tenant_plan tariff requires target_plan_key'], 422);
            return;
        }
        if ($targetPlanKey !== '' && !in_array(TenantPlanCatalog::definition($targetPlanKey)['key'], TenantPlanCatalog::selectablePlanKeys(), true)) {
            Response::json(['error' => 'Invalid target_plan_key'], 422);
            return;
        }

        $tariffId = $repo->createTariff(
            $productId,
            $licenseType,
            $stripePriceId,
            $purchaseKind,
            $targetPlanKey !== '' ? TenantPlanCatalog::definition($targetPlanKey)['key'] : null,
            $price,
            $currency,
            (bool) ($request->json['is_recommended'] ?? false),
            (bool) ($request->json['is_default'] ?? false),
            (int) ($request->json['sort_order'] ?? 0)
        );

        Response::json([
            'success' => true,
            'tariff' => $repo->findTariffByIdForTenant($tariffId, (int) $current['tenant_id']),
        ], 201);
    }

    /** @param array<string,string> $routeParams */
    public static function tariffDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $tariff = (new ProductRepository())->findTariffByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($tariff === null) {
            Response::json(['error' => 'Tariff not found'], 404);
            return;
        }

        Response::json(['tariff' => $tariff]);
    }

    /** @param array<string,string> $routeParams */
    public static function tariffUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $repo = new ProductRepository();
        $tariffId = (int) ($routeParams['id'] ?? 0);
        $tariff = $repo->findTariffByIdForTenant($tariffId, (int) $current['tenant_id']);
        if ($tariff === null) {
            Response::json(['error' => 'Tariff not found'], 404);
            return;
        }

        $currency = CurrencyCatalog::normalize((string) ($request->json['currency'] ?? (string) $tariff['currency']));
        $licenseType = trim((string) ($request->json['license_type'] ?? (string) $tariff['license_type']));
        $stripePriceId = trim((string) ($request->json['stripe_price_id'] ?? (string) $tariff['stripe_price_id']));
        $purchaseKind = trim((string) ($request->json['purchase_kind'] ?? (string) ($tariff['purchase_kind'] ?? 'license')));
        $targetPlanKey = trim((string) ($request->json['target_plan_key'] ?? (string) ($tariff['target_plan_key'] ?? '')));
        $price = (float) ($request->json['price'] ?? (float) $tariff['price']);
        if (!in_array($licenseType, ['monthly', 'annual', 'lifetime'], true)
            || !in_array($purchaseKind, ['license', 'tenant_plan'], true)
            || $stripePriceId === ''
            || $price <= 0
            || $currency === null
        ) {
            Response::json(['error' => 'Invalid tariff payload'], 422);
            return;
        }
        if ($purchaseKind === 'tenant_plan' && $targetPlanKey === '') {
            Response::json(['error' => 'tenant_plan tariff requires target_plan_key'], 422);
            return;
        }
        if ($targetPlanKey !== '' && !in_array(TenantPlanCatalog::definition($targetPlanKey)['key'], TenantPlanCatalog::selectablePlanKeys(), true)) {
            Response::json(['error' => 'Invalid target_plan_key'], 422);
            return;
        }

        $isDefault = (bool) ($request->json['is_default'] ?? (bool) $tariff['is_default']);
        if ($isDefault) {
            $repo->clearDefaultTariffs((int) $tariff['product_id']);
        }

        $repo->updateTariff($tariffId, [
            'license_type' => $licenseType,
            'stripe_price_id' => $stripePriceId,
            'purchase_kind' => $purchaseKind,
            'target_plan_key' => $targetPlanKey !== '' ? TenantPlanCatalog::definition($targetPlanKey)['key'] : null,
            'price' => $price,
            'currency' => $currency,
            'is_recommended' => (int) ((bool) ($request->json['is_recommended'] ?? (bool) $tariff['is_recommended'])),
            'is_default' => $isDefault ? 1 : 0,
            'is_active' => (int) ($request->json['is_active'] ?? (int) $tariff['is_active']),
            'sort_order' => (int) ($request->json['sort_order'] ?? (int) $tariff['sort_order']),
        ]);

        Response::json([
            'success' => true,
            'tariff' => $repo->findTariffByIdForTenant($tariffId, (int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appCurrenciesDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $tenant = (new TenantRepository())->findById((int) $current['tenant_id']);
        $tenantSettings = (new TenantSettingsRepository())->allForTenant((int) $current['tenant_id']);

        Response::json([
            'app' => [
                'id' => (int) $app['id'],
                'slug' => (string) $app['slug'],
                'name' => (string) $app['name'],
                'default_currency' => (string) ($app['default_currency'] ?: ($tenant['default_currency'] ?? 'EUR')),
                'enabled_currencies' => self::normalizeCurrencies(
                    (string) ($app['enabled_currencies'] ?? ''),
                    self::tenantEnabledCurrencies($tenantSettings)
                ),
            ],
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appCurrenciesUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $apps = new AppRepository();
        $app = $apps->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $tenantSettings = (new TenantSettingsRepository())->allForTenant((int) $current['tenant_id']);
        $tenantEnabled = self::tenantEnabledCurrencies($tenantSettings);
        $enabledCurrencies = self::normalizeCurrencies(
            implode(',', (array) ($request->json['enabled_currencies'] ?? [])),
            $tenantEnabled
        );
        $defaultCurrency = CurrencyCatalog::normalize((string) ($request->json['default_currency'] ?? ''));
        if ($defaultCurrency === null || !in_array($defaultCurrency, $enabledCurrencies, true)) {
            Response::json(['error' => 'Invalid app currency payload'], 422);
            return;
        }

        $apps->update($appId, [
            'default_currency' => $defaultCurrency,
            'enabled_currencies' => implode(',', $enabledCurrencies),
        ]);

        $updated = $apps->findByIdForTenant($appId, (int) $current['tenant_id']);
        Response::json([
            'success' => true,
            'app' => [
                'id' => (int) $updated['id'],
                'slug' => (string) $updated['slug'],
                'name' => (string) $updated['name'],
                'default_currency' => (string) $updated['default_currency'],
                'enabled_currencies' => self::normalizeCurrencies((string) $updated['enabled_currencies'], $tenantEnabled),
            ],
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function stripeConfig(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        Response::json([
            'stripe' => (new CustomerStripeService())->getConfig((int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function stripeConfigUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        (new CustomerStripeService())->update((int) $current['tenant_id'], $request->json);
        Response::json([
            'success' => true,
            'stripe' => (new CustomerStripeService())->getConfig((int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function stripeConfigTest(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $simulated = (new DemoTenantService())->simulatedCustomerAction($current, 'stripe_test');
        if ($simulated !== null) {
            Response::json($simulated);
            return;
        }

        $result = (new CustomerStripeService())->testConnection((int) $current['tenant_id']);
        Response::json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    /** @param array<string,string> $routeParams */
    public static function smtpConfig(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        Response::json([
            'smtp' => (new CustomerSmtpService())->getConfig((int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function smtpConfigUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $port = (int) ($request->json['port'] ?? 587);
        $encryption = trim((string) ($request->json['encryption'] ?? 'tls'));
        if ($port < 1 || $port > 65535 || !in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            Response::json(['error' => 'Invalid SMTP payload'], 422);
            return;
        }

        (new CustomerSmtpService())->update((int) $current['tenant_id'], $request->json);
        Response::json([
            'success' => true,
            'smtp' => (new CustomerSmtpService())->getConfig((int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function smtpConfigTest(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $service = new CustomerSmtpService();
        $mode = trim((string) ($request->json['mode'] ?? 'connection'));
        if ($mode === 'email') {
            $toEmail = trim((string) ($request->json['to_email'] ?? ''));
            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                Response::json(['error' => 'Valid to_email is required'], 422);
                return;
            }
            $simulated = (new DemoTenantService())->simulatedCustomerAction($current, 'smtp_test');
            if ($simulated !== null) {
                Response::json($simulated);
                return;
            }
            $result = $service->sendTestEmail((int) $current['tenant_id'], $toEmail);
        } else {
            $simulated = (new DemoTenantService())->simulatedCustomerAction($current, 'smtp_test');
            if ($simulated !== null) {
                Response::json($simulated);
                return;
            }
            $result = $service->testConnection((int) $current['tenant_id']);
        }

        Response::json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    /** @param array<string,string> $routeParams */
    public static function googleAnalyticsDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requirePlanFeature((int) $current['tenant_id'], 'analytics')) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $app = (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $integration = (new GoogleAnalyticsRepository())->findByAppIdForTenant($appId, (int) $current['tenant_id']);
        Response::json([
            'app' => self::appSummary($app),
            'google_analytics' => [
                'is_enabled' => (bool) ($integration['is_enabled'] ?? false),
                'measurement_id' => (string) ($integration['measurement_id'] ?? ''),
                'website_url' => (string) ($integration['website_url'] ?? ''),
                'property_name' => (string) ($integration['property_name'] ?? ''),
                'admin_link' => (string) ($integration['admin_link'] ?? ''),
                'notes' => (string) ($integration['notes'] ?? ''),
            ],
            'checklist' => (new GoogleAnalyticsService())->integrationChecklist(),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function googleAnalyticsConfigs(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requirePlanFeature((int) $current['tenant_id'], 'analytics')) {
            return;
        }

        $apps = (new AppRepository())->listAllForTenant((int) $current['tenant_id']);
        $repo = new GoogleAnalyticsRepository();
        $service = new GoogleAnalyticsService();
        $payload = [];
        foreach ($apps as $app) {
            $integration = $repo->findByAppIdForTenant((int) $app['id'], (int) $current['tenant_id']);
            $payload[] = [
                'app' => self::appSummary($app),
                'google_analytics' => [
                    'is_enabled' => (bool) ($integration['is_enabled'] ?? false),
                    'measurement_id' => (string) ($integration['measurement_id'] ?? ''),
                    'website_url' => (string) ($integration['website_url'] ?? ''),
                    'property_name' => (string) ($integration['property_name'] ?? ''),
                    'admin_link' => (string) ($integration['admin_link'] ?? ''),
                    'notes' => (string) ($integration['notes'] ?? ''),
                ],
            ];
        }

        Response::json([
            'integrations' => $payload,
            'checklist' => $service->integrationChecklist(),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function googleAnalyticsConfigsUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }
        if (!self::requirePlanFeature((int) $current['tenant_id'], 'analytics')) {
            return;
        }

        $appId = (int) ($request->json['app_id'] ?? 0);
        if ($appId <= 0) {
            Response::json(['error' => 'app_id is required'], 422);
            return;
        }

        self::googleAnalyticsUpdate($request, ['id' => (string) $appId]);
    }

    /** @param array<string,string> $routeParams */
    public static function googleAnalyticsUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }
        if (!self::requirePlanFeature((int) $current['tenant_id'], 'analytics')) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $app = (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $service = new GoogleAnalyticsService();
        $measurementId = $service->normalizeMeasurementId((string) ($request->json['measurement_id'] ?? ''));
        if (!$service->isValidMeasurementId($measurementId)) {
            Response::json(['error' => 'Invalid measurement_id'], 422);
            return;
        }

        (new GoogleAnalyticsRepository())->upsertForApp($appId, [
            'is_enabled' => (bool) ($request->json['is_enabled'] ?? false),
            'measurement_id' => $measurementId,
            'website_url' => trim((string) ($request->json['website_url'] ?? '')) ?: null,
            'property_name' => trim((string) ($request->json['property_name'] ?? '')) ?: null,
            'admin_link' => trim((string) ($request->json['admin_link'] ?? '')) ?: null,
            'notes' => trim((string) ($request->json['notes'] ?? '')) ?: null,
        ]);

        self::googleAnalyticsDetail($request, ['id' => (string) $appId]);
    }

    /** @param array<string,string> $routeParams */
    public static function aiInsightsConfig(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $tenantId = (int) $current['tenant_id'];
        Response::json([
            'settings' => self::aiSettingsPayload((new AISettingsRepository())->findByModuleKeyForTenant($tenantId, 'ai_insights')),
            'recent_runs' => (new AIInsightsRepository())->recentForTenant($tenantId, null, 20),
            'types' => AIInsightsService::insightTypes(),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function aiInsightsConfigUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $enabledAppIds = self::tenantAppIds((int) $current['tenant_id'], (array) ($request->json['enabled_app_ids'] ?? []));

        (new AISettingsRepository())->upsertByModuleKey('ai_insights', [
            'tenant_id' => (int) $current['tenant_id'],
            'is_enabled' => (bool) ($request->json['is_enabled'] ?? false),
            'provider_name' => trim((string) ($request->json['provider_name'] ?? '')) ?: null,
            'model_name' => trim((string) ($request->json['model_name'] ?? '')) ?: null,
            'api_key' => (string) ($request->json['api_key'] ?? null),
            'daily_request_limit' => (int) ($request->json['daily_request_limit'] ?? 50),
            'store_prompts' => (bool) ($request->json['store_prompts'] ?? false),
            'redact_sensitive_data' => (bool) ($request->json['redact_sensitive_data'] ?? true),
            'enabled_app_ids' => $enabledAppIds,
        ]);

        self::aiInsightsConfig($request, []);
    }

    /** @param array<string,string> $routeParams */
    public static function aiInsightsRun(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $appId = isset($request->json['app_id']) ? (int) $request->json['app_id'] : null;
        if ($appId !== null && (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']) === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $insightType = trim((string) ($request->json['insight_type'] ?? 'ops_summary'));
        if (!array_key_exists($insightType, AIInsightsService::insightTypes())) {
            Response::json(['error' => 'Invalid insight_type'], 422);
            return;
        }

        $simulated = (new DemoTenantService())->simulatedCustomerAction($current, 'ai_insights_run');
        if ($simulated !== null) {
            Response::json($simulated);
            return;
        }

        $runId = (new AIInsightsService())->run($appId, $insightType, null);
        Response::json([
            'success' => true,
            'run_id' => $runId,
            'recent_runs' => (new AIInsightsRepository())->recentForTenant((int) $current['tenant_id'], $appId, 20),
        ], 201);
    }

    /** @param array<string,string> $routeParams */
    public static function aiAssistantConfig(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $tenantId = (int) $current['tenant_id'];
        Response::json([
            'settings' => self::aiSettingsPayload((new AISettingsRepository())->findByModuleKeyForTenant($tenantId, 'ai_assistant')),
            'recent_runs' => (new AIAssistantRepository())->recentForTenant($tenantId, null, 20),
            'types' => AIAssistantService::taskTypes(),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function aiAssistantConfigUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $enabledAppIds = self::tenantAppIds((int) $current['tenant_id'], (array) ($request->json['enabled_app_ids'] ?? []));

        (new AISettingsRepository())->upsertByModuleKey('ai_assistant', [
            'tenant_id' => (int) $current['tenant_id'],
            'is_enabled' => (bool) ($request->json['is_enabled'] ?? false),
            'provider_name' => trim((string) ($request->json['provider_name'] ?? '')) ?: null,
            'model_name' => trim((string) ($request->json['model_name'] ?? '')) ?: null,
            'api_key' => (string) ($request->json['api_key'] ?? null),
            'daily_request_limit' => (int) ($request->json['daily_request_limit'] ?? 50),
            'store_prompts' => (bool) ($request->json['store_prompts'] ?? false),
            'redact_sensitive_data' => (bool) ($request->json['redact_sensitive_data'] ?? true),
            'enabled_app_ids' => $enabledAppIds,
        ]);

        self::aiAssistantConfig($request, []);
    }

    /** @param array<string,string> $routeParams */
    public static function aiAssistantRun(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $appId = isset($request->json['app_id']) ? (int) $request->json['app_id'] : null;
        if ($appId !== null && (new AppRepository())->findByIdForTenant($appId, (int) $current['tenant_id']) === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $taskType = trim((string) ($request->json['task_type'] ?? 'support_reply'));
        if (!array_key_exists($taskType, AIAssistantService::taskTypes())) {
            Response::json(['error' => 'Invalid task_type'], 422);
            return;
        }

        $simulated = (new DemoTenantService())->simulatedCustomerAction($current, 'ai_assistant_run');
        if ($simulated !== null) {
            Response::json($simulated);
            return;
        }

        $runId = (new AIAssistantService())->run(
            $appId,
            $taskType,
            trim((string) ($request->json['input_text'] ?? '')),
            null
        );

        Response::json([
            'success' => true,
            'run_id' => $runId,
            'recent_runs' => (new AIAssistantRepository())->recentForTenant((int) $current['tenant_id'], $appId, 20),
        ], 201);
    }

    /** @param array<string,string> $routeParams */
    public static function appNotificationsDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $profile = (new AppNotificationRepository())->profileForApp((int) $app['id']) ?? self::defaultNotificationProfile();
        Response::json([
            'app' => self::appSummary($app),
            'notifications' => self::notificationProfilePayload($profile),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appNotificationsUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        (new AppNotificationRepository())->upsertProfile((int) $app['id'], [
            'is_enabled' => (bool) ($request->json['is_enabled'] ?? true),
            'sender_name' => trim((string) ($request->json['sender_name'] ?? '')) ?: null,
            'reply_to_email' => trim((string) ($request->json['reply_to_email'] ?? '')) ?: null,
            'sales_email' => trim((string) ($request->json['sales_email'] ?? '')) ?: null,
            'ops_email' => trim((string) ($request->json['ops_email'] ?? '')) ?: null,
            'support_url' => trim((string) ($request->json['support_url'] ?? '')) ?: null,
            'brand_color' => trim((string) ($request->json['brand_color'] ?? '')) ?: null,
        ]);

        $profile = (new AppNotificationRepository())->profileForApp((int) $app['id']) ?? self::defaultNotificationProfile();
        Response::json([
            'success' => true,
            'app' => self::appSummary($app),
            'notifications' => self::notificationProfilePayload($profile),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appTemplatesList(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $templates = (new EmailTemplateRepository())->listAll();
        $overrides = (new AppNotificationRepository())->templateOverridesForApp((int) $app['id']);
        $overrideMap = [];
        foreach ($overrides as $override) {
            $overrideMap[(string) $override['template_key']] = $override;
        }

        $payload = [];
        foreach ($templates as $template) {
            $payload[] = self::templatePayload($template, $overrideMap[(string) $template['template_key']] ?? null);
        }

        Response::json([
            'app' => self::appSummary($app),
            'templates' => $payload,
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appTemplateDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $template = (new EmailTemplateRepository())->findByKey((string) ($routeParams['template_key'] ?? ''));
        if ($template === null) {
            Response::json(['error' => 'Template not found'], 404);
            return;
        }

        $override = (new AppNotificationRepository())->templateOverrideForApp((int) $app['id'], (string) $template['template_key']);
        Response::json([
            'app' => self::appSummary($app),
            'template' => self::templatePayload($template, $override),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appTemplateUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $templateKey = (string) ($routeParams['template_key'] ?? '');
        $template = (new EmailTemplateRepository())->findByKey($templateKey);
        if ($template === null) {
            Response::json(['error' => 'Template not found'], 404);
            return;
        }

        (new AppNotificationRepository())->upsertTemplateOverride(
            (int) $app['id'],
            $templateKey,
            trim((string) ($request->json['subject'] ?? '')) ?: null,
            trim((string) ($request->json['body'] ?? '')) ?: null,
            (bool) ($request->json['is_enabled'] ?? true)
        );

        $override = (new AppNotificationRepository())->templateOverrideForApp((int) $app['id'], $templateKey);
        Response::json([
            'success' => true,
            'app' => self::appSummary($app),
            'template' => self::templatePayload($template, $override),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appTemplateTest(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $template = (new EmailTemplateRepository())->findByKey((string) ($routeParams['template_key'] ?? ''));
        if ($template === null) {
            Response::json(['error' => 'Template not found'], 404);
            return;
        }

        $toEmail = trim((string) ($request->json['to_email'] ?? ''));
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            Response::json(['error' => 'Valid to_email is required'], 422);
            return;
        }

        $override = (new AppNotificationRepository())->templateOverrideForApp((int) $app['id'], (string) $template['template_key']);
        $rendered = self::templatePayload($template, $override);
        $simulated = (new DemoTenantService())->simulatedCustomerAction($current, 'template_test', [
            'subject' => (string) $rendered['effective_subject'],
            'body' => (string) $rendered['effective_body'],
        ]);
        if ($simulated !== null) {
            Response::json($simulated);
            return;
        }

        $smtp = new CustomerSmtpService();
        $result = $smtp->sendRawEmail(
            (int) $current['tenant_id'],
            $toEmail,
            (string) $rendered['effective_subject'],
            nl2br((string) $rendered['effective_body']),
            strip_tags((string) $rendered['effective_body'])
        );
        $result['preview'] = [
            'subject' => $rendered['effective_subject'],
            'body' => $rendered['effective_body'],
        ];

        Response::json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    /** @param array<string,string> $routeParams */
    public static function appPoliciesDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        Response::json([
            'app' => self::appSummary($app),
            'policies' => self::policyScopePayload('app', (int) $app['id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appPoliciesUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        if (!self::writePolicyScope('app', (int) $app['id'], $request->json)) {
            return;
        }

        Response::json([
            'success' => true,
            'app' => self::appSummary($app),
            'policies' => self::policyScopePayload('app', (int) $app['id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function productPoliciesDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $product = (new ProductRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($product === null) {
            Response::json(['error' => 'Product not found'], 404);
            return;
        }

        Response::json([
            'product' => [
                'id' => (int) $product['id'],
                'slug' => (string) $product['slug'],
                'name' => (string) $product['name'],
            ],
            'policies' => self::policyScopePayload('product', (int) $product['id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function productPoliciesUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $product = (new ProductRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($product === null) {
            Response::json(['error' => 'Product not found'], 404);
            return;
        }

        if (!self::writePolicyScope('product', (int) $product['id'], $request->json)) {
            return;
        }

        Response::json([
            'success' => true,
            'product' => [
                'id' => (int) $product['id'],
                'slug' => (string) $product['slug'],
                'name' => (string) $product['name'],
            ],
            'policies' => self::policyScopePayload('product', (int) $product['id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function tariffPoliciesDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $tariff = (new ProductRepository())->findTariffByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($tariff === null) {
            Response::json(['error' => 'Tariff not found'], 404);
            return;
        }

        Response::json([
            'tariff' => self::tariffSummary($tariff),
            'policies' => self::policyScopePayload('tariff', (int) $tariff['id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function tariffPoliciesUpdate(Request $request, array $routeParams): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $tariff = (new ProductRepository())->findTariffByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($tariff === null) {
            Response::json(['error' => 'Tariff not found'], 404);
            return;
        }

        if (!self::writePolicyScope('tariff', (int) $tariff['id'], $request->json)) {
            return;
        }

        Response::json([
            'success' => true,
            'tariff' => self::tariffSummary($tariff),
            'policies' => self::policyScopePayload('tariff', (int) $tariff['id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function licensesList(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 50)));
        $licenses = (new LicenseRepository())->listForTenant((int) $current['tenant_id'], [
            'app_id' => (int) ($request->query['app_id'] ?? 0),
            'status' => $request->query['status'] ?? null,
            'search' => $request->query['search'] ?? null,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);

        Response::json([
            'licenses' => $licenses,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function licenseDetail(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $license = (new LicenseRepository())->detailForTenant((int) $current['tenant_id'], (string) ($routeParams['key'] ?? ''));
        if ($license === null) {
            Response::json(['error' => 'License not found'], 404);
            return;
        }

        Response::json(['license' => $license]);
    }

    /** @param array<string,string> $routeParams */
    public static function licenseActivations(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $license = (new LicenseRepository())->detailForTenant((int) $current['tenant_id'], (string) ($routeParams['key'] ?? ''));
        if ($license === null) {
            Response::json(['error' => 'License not found'], 404);
            return;
        }

        Response::json([
            'license' => self::licenseSummary($license),
            'activations' => (new ActivationRepository())->listForLicense((int) $license['id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function licenseVerifications(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $license = (new LicenseRepository())->detailForTenant((int) $current['tenant_id'], (string) ($routeParams['key'] ?? ''));
        if ($license === null) {
            Response::json(['error' => 'License not found'], 404);
            return;
        }

        Response::json([
            'license' => self::licenseSummary($license),
            'verifications' => (new VerificationRepository())->listForLicense((int) $license['id']),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function webhooksList(Request $request, array $routeParams): void
    {
        unset($routeParams);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requirePlanFeature((int) $current['tenant_id'], 'webhooks')) {
            return;
        }

        $apps = (new AppRepository())->listAllForTenant((int) $current['tenant_id']);
        $appSlugs = array_values(array_filter(array_map(
            static fn (array $app): string => (string) ($app['slug'] ?? ''),
            $apps
        )));
        $limit = min(100, max(1, (int) ($request->query['limit'] ?? 50)));
        $webhooks = (new WebhookLogRepository())->listForTenantApps($appSlugs, $limit);

        Response::json([
            'webhooks' => $webhooks,
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appIntegrationGuide(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        Response::json([
            'app' => self::appSummary($app),
            'integration_guide' => IntegrationGuideBuilder::build(
                (string) $app['slug'],
                (string) ($app['platform_type'] ?? ''),
                self::offlinePublicKeyUrl((int) $app['id'])
            ),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function appOfflinePublicKey(Request $request, array $routeParams): void
    {
        unset($request);
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }

        $app = (new AppRepository())->findByIdForTenant((int) ($routeParams['id'] ?? 0), (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $key = (new OfflineTokenSigner())->publicKeyData((int) $current['tenant_id']);
        Response::json([
            'app' => self::appSummary($app),
            'offline_verification' => [
                'algorithm' => 'RS256',
                'key_id' => $key['key_id'],
                'public_key_pem' => $key['public_key_pem'],
            ],
        ]);
    }

    /** @return array<string,mixed>|null */
    private static function requireCustomer(): ?array
    {
        $current = (new CustomerSessionService())->current();
        if ($current === null) {
            Response::json(['error' => 'Unauthorized'], 401);
            return null;
        }

        return $current;
    }

    private static function throttle(Request $request, string $endpoint, int $limit): bool
    {
        $rl = new RateLimiter();
        if (!$rl->allow($request->ip, $endpoint, $limit)) {
            Response::json(['error' => 'Too many requests'], 429);
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $current
      * @param array<int,string> $roles */
    private static function requireTenantRole(array $current, array $roles): bool
    {
        $role = (string) ($current['role'] ?? '');
        if (in_array($role, $roles, true)) {
            return true;
        }

        Response::json(['error' => 'Forbidden'], 403);
        return false;
    }

    /** @param array<string,mixed>|null $current
      * @return array<string,mixed> */
    private static function mePayload(?array $current): array
    {
        if ($current === null) {
            return [];
        }

        return [
            'user' => [
                'id' => (int) $current['tenant_user_id'],
                'email' => (string) ($current['user']['email'] ?? ''),
                'name' => (string) ($current['user']['name'] ?? ''),
            ],
            'membership' => [
                'role' => (string) ($current['role'] ?? 'owner'),
            ],
            'tenant' => [
                'id' => (int) $current['tenant_id'],
                'slug' => (string) ($current['membership']['tenant_slug'] ?? ''),
                'name' => (string) ($current['membership']['tenant_name'] ?? ''),
                'status' => (string) ($current['membership']['tenant_status'] ?? ''),
                'plan_key' => (string) ($current['membership']['plan_key'] ?? ''),
                'timezone' => (string) ($current['membership']['timezone'] ?? ''),
                'default_currency' => (string) ($current['membership']['default_currency'] ?? 'EUR'),
                'is_demo' => (bool) ($current['membership']['is_demo'] ?? false),
            ],
            'billing' => (new TenantBillingService())->summaryForTenant((int) $current['tenant_id']),
            'memberships' => self::membershipsPayload((array) ($current['memberships'] ?? [])),
        ];
    }

    private static function requirePlanFeature(int $tenantId, string $featureKey): bool
    {
        try {
            (new TenantBillingService())->assertFeatureEnabled($tenantId, $featureKey);
            return true;
        } catch (\RuntimeException | \DomainException $e) {
            Response::json([
                'error' => ClientError::message($e),
                'billing' => (new TenantBillingService())->summaryForTenant($tenantId),
            ], 403);
            return false;
        }
    }

    private static function requireAnalyticsLevel(int $tenantId, string $requiredLevel): bool
    {
        $summary = (new TenantBillingService())->summaryForTenant($tenantId);
        $currentLevel = (string) ($summary['plan']['analytics_level'] ?? 'none');
        $levels = ['none' => 0, 'basic' => 1, 'advanced' => 2];
        if (($levels[$currentLevel] ?? 0) < ($levels[$requiredLevel] ?? 0)) {
            Response::json([
                'error' => 'Current plan does not include this analytics level.',
                'billing' => $summary,
            ], 403);
            return false;
        }

        return true;
    }

    /** @param array<string,mixed>|null $tenant
      * @param array<string,string> $settings
      * @return array<string,mixed> */
    private static function tenantPayload(?array $tenant, array $settings): array
    {
        if ($tenant === null) {
            return [];
        }

        return [
            'id' => (int) $tenant['id'],
            'slug' => (string) $tenant['slug'],
            'name' => (string) $tenant['name'],
            'status' => (string) $tenant['status'],
            'plan_key' => (string) ($tenant['plan_key'] ?? ''),
            'timezone' => (string) ($tenant['timezone'] ?? 'Europe/Prague'),
            'default_currency' => (string) ($tenant['default_currency'] ?? 'EUR'),
            'enabled_currencies' => self::tenantEnabledCurrencies($settings),
            'is_demo' => (bool) ($tenant['is_demo'] ?? false),
        ];
    }

    /** @param array<string,string> $settings
      * @return string[] */
    private static function tenantEnabledCurrencies(array $settings): array
    {
        return self::normalizeCurrencies((string) ($settings['enabled_currencies'] ?? ''), CurrencyCatalog::all());
    }

    private static function offlinePublicKeyUrl(int $appId): string
    {
        return rtrim((string) \App\Support\Env::get('APP_URL', ''), '/') . '/customer-api/apps/' . $appId . '/offline-public-key';
    }

    /** @return string[] */
    private static function normalizeCurrencies(string $raw, array $fallback): array
    {
        $currencies = array_values(array_filter(array_map(
            static fn (string $value): ?string => CurrencyCatalog::normalize($value),
            array_map('trim', explode(',', $raw))
        )));

        return $currencies !== [] ? array_values(array_unique($currencies)) : $fallback;
    }

    /** @return array<int,array<string,mixed>> */
    private static function policyScopePayload(string $scope, int $scopeId): array
    {
        $repo = new PolicyRepository();
        $payload = [];
        foreach (PolicyCatalog::definitions() as $policyKey => $definition) {
            $row = match ($scope) {
                'app' => $repo->findAppPolicy($scopeId, $policyKey),
                'product' => $repo->findProductPolicy($scopeId, $policyKey),
                'tariff' => $repo->findTariffPolicy($scopeId, $policyKey),
                default => null,
            };

            $payload[] = [
                'policy_key' => $policyKey,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'placeholder' => $definition['placeholder'],
                'is_enabled' => (bool) ($row['is_enabled'] ?? false),
                'policy_value' => self::policyValueForApi($row['policy_value'] ?? null),
            ];
        }

        return $payload;
    }

    private static function writePolicyScope(string $scope, int $scopeId, array $payload): bool
    {
        $policyKey = trim((string) ($payload['policy_key'] ?? ''));
        if (!PolicyCatalog::has($policyKey)) {
            Response::json(['error' => 'Unknown policy key'], 422);
            return false;
        }

        $repo = new PolicyRepository();
        $policyValue = self::decodePolicyValueForWrite((string) ($payload['policy_value'] ?? ''));
        $isEnabled = (bool) ($payload['is_enabled'] ?? false);

        match ($scope) {
            'app' => $repo->upsertAppPolicy($scopeId, $policyKey, $policyValue, $isEnabled),
            'product' => $repo->upsertProductPolicy($scopeId, $policyKey, $policyValue, $isEnabled),
            'tariff' => $repo->upsertTariffPolicy($scopeId, $policyKey, $policyValue, $isEnabled),
            default => null,
        };

        return true;
    }

    private static function decodePolicyValueForWrite(string $raw): mixed
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        if ($trimmed === 'true') {
            return true;
        }
        if ($trimmed === 'false') {
            return false;
        }

        return $trimmed;
    }

    private static function policyValueForApi(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }

            return $trimmed;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @return array<string,mixed> */
    private static function defaultNotificationProfile(): array
    {
        return [
            'is_enabled' => 1,
            'sender_name' => '',
            'reply_to_email' => '',
            'sales_email' => '',
            'ops_email' => '',
            'support_url' => '',
            'brand_color' => '#51cbce',
        ];
    }

    /** @param array<string,mixed> $app
      * @return array<string,mixed> */
    private static function appSummary(array $app): array
    {
        return [
            'id' => (int) $app['id'],
            'slug' => (string) $app['slug'],
            'name' => (string) $app['name'],
        ];
    }

    /** @param array<string,string> $routeParams */
    private static function setAppActiveState(array $routeParams, bool $isActive): void
    {
        $current = self::requireCustomer();
        if ($current === null) {
            return;
        }
        if (!self::requireTenantRole($current, ['owner'])) {
            return;
        }

        $appId = (int) ($routeParams['id'] ?? 0);
        $repo = new AppRepository();
        $app = $repo->findByIdForTenant($appId, (int) $current['tenant_id']);
        if ($app === null) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $repo->update($appId, ['is_active' => $isActive ? 1 : 0]);
        Response::json([
            'success' => true,
            'app' => $repo->findByIdForTenant($appId, (int) $current['tenant_id']),
        ]);
    }

    /** @param array<string,mixed> $license
      * @return array<string,mixed> */
    private static function licenseSummary(array $license): array
    {
        return [
            'id' => (int) $license['id'],
            'license_key' => (string) $license['license_key'],
            'license_type' => (string) $license['license_type'],
            'status' => (string) $license['status'],
            'app_id' => isset($license['app_id']) ? (int) $license['app_id'] : null,
            'app_slug' => (string) ($license['app_slug'] ?? ''),
            'app_name' => (string) ($license['app_name'] ?? ''),
            'customer_email' => (string) ($license['customer_email'] ?? ''),
            'product_slug' => (string) ($license['product_slug'] ?? ''),
            'product_name' => (string) ($license['product_name'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $tariff
      * @return array<string,mixed> */
    private static function tariffSummary(array $tariff): array
    {
        return [
            'id' => (int) $tariff['id'],
            'product_id' => (int) $tariff['product_id'],
            'license_type' => (string) $tariff['license_type'],
            'currency' => (string) $tariff['currency'],
            'price' => (float) $tariff['price'],
        ];
    }

    /** @param array<string,mixed> $profile
      * @return array<string,mixed> */
    private static function notificationProfilePayload(array $profile): array
    {
        return [
            'is_enabled' => (bool) ($profile['is_enabled'] ?? false),
            'sender_name' => (string) ($profile['sender_name'] ?? ''),
            'reply_to_email' => (string) ($profile['reply_to_email'] ?? ''),
            'sales_email' => (string) ($profile['sales_email'] ?? ''),
            'ops_email' => (string) ($profile['ops_email'] ?? ''),
            'support_url' => (string) ($profile['support_url'] ?? ''),
            'brand_color' => (string) ($profile['brand_color'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $template
      * @param array<string,mixed>|null $override
      * @return array<string,mixed> */
    private static function templatePayload(array $template, ?array $override): array
    {
        $placeholders = [
            '{customer_name}' => 'Test Customer',
            '{customer_email}' => 'test@example.com',
            '{license_key}' => 'LCMS-TEST-KEY',
            '{product_name}' => 'Sample Product',
            '{license_type}' => 'lifetime',
            '{expires_at}' => '31. 12. 2026',
            '{activation_count}' => '1',
            '{max_activations}' => '3',
            '{{name}}' => 'Test Customer',
            '{{email}}' => 'test@example.com',
            '{{key}}' => 'LCMS-TEST-KEY',
            '{{type}}' => 'Dozivotni licence',
            '{{expires}}' => '31. 12. 2026',
            '{{price}}' => '29.99 EUR',
            '{{currency}}' => 'EUR',
        ];

        $effectiveSubject = trim((string) ($override['subject'] ?? '')) ?: (string) $template['subject'];
        $effectiveBody = trim((string) ($override['body'] ?? '')) ?: (string) $template['body'];

        return [
            'template_key' => (string) $template['template_key'],
            'available_placeholders' => (string) ($template['available_placeholders'] ?? ''),
            'default_subject' => (string) $template['subject'],
            'default_body' => (string) $template['body'],
            'override' => [
                'is_enabled' => (bool) ($override['is_enabled'] ?? false),
                'subject' => (string) ($override['subject'] ?? ''),
                'body' => (string) ($override['body'] ?? ''),
            ],
            'effective_subject' => str_replace(array_keys($placeholders), array_values($placeholders), $effectiveSubject),
            'effective_body' => str_replace(array_keys($placeholders), array_values($placeholders), $effectiveBody),
        ];
    }

    /** @param array<int,array<string,mixed>> $memberships
      * @return array<int,array<string,mixed>> */
    private static function membershipsPayload(array $memberships): array
    {
        return array_values(array_map(static function (array $membership): array {
            return [
                'tenant_id' => (int) $membership['tenant_id'],
                'role' => (string) ($membership['role'] ?? 'owner'),
                'tenant' => [
                    'slug' => (string) ($membership['tenant_slug'] ?? ''),
                    'name' => (string) ($membership['tenant_name'] ?? ''),
                    'status' => (string) ($membership['tenant_status'] ?? ''),
                    'plan_key' => (string) ($membership['plan_key'] ?? ''),
                    'timezone' => (string) ($membership['timezone'] ?? 'Europe/Prague'),
                    'default_currency' => (string) ($membership['default_currency'] ?? 'EUR'),
                    'is_demo' => (bool) ($membership['is_demo'] ?? false),
                ],
            ];
        }, $memberships));
    }

    /** @param array<string,mixed>|null $settings
      * @return array<string,mixed> */
    private static function aiSettingsPayload(?array $settings): array
    {
        return [
            'is_enabled' => (bool) ($settings['is_enabled'] ?? false),
            'provider_name' => (string) ($settings['provider_name'] ?? ''),
            'model_name' => (string) ($settings['model_name'] ?? ''),
            'api_key_configured' => trim((string) ($settings['api_key'] ?? '')) !== '',
            'daily_request_limit' => (int) ($settings['daily_request_limit'] ?? 50),
            'store_prompts' => (bool) ($settings['store_prompts'] ?? false),
            'redact_sensitive_data' => (bool) ($settings['redact_sensitive_data'] ?? true),
            'enabled_app_ids' => array_values(array_map('intval', (array) ($settings['enabled_app_ids'] ?? []))),
        ];
    }

    /** @param array<int,mixed> $requested
      * @return int[] */
    private static function tenantAppIds(int $tenantId, array $requested): array
    {
        $allowed = array_map(
            static fn (array $app): int => (int) $app['id'],
            (new AppRepository())->listAllForTenant($tenantId)
        );

        return array_values(array_filter(
            array_values(array_unique(array_map('intval', $requested))),
            static fn (int $appId): bool => in_array($appId, $allowed, true)
        ));
    }
}
