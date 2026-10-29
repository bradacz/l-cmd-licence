<?php

declare(strict_types=1);

namespace App\Http;

use App\Repositories\AuditLogRepository;
use App\Repositories\AppProductRepository;
use App\Repositories\AppRepository;
use App\Repositories\AppContextRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SettingsRepository;
use App\Services\AppContextService;
use App\Services\AuthService;
use App\Services\CheckoutService;
use App\Services\LicenseKeyGenerator;
use App\Services\LicenseService;
use App\Services\PublicFormService;
use App\Services\RateLimiter;
use App\Services\StripeWebhookService;
use App\Services\TenantBillingService;
use App\Services\TenantContextService;
use App\Services\TenantOverageBillingService;
use App\Support\CurrencyCatalog;

final class Api
{
    /** @param array<string,string> $routeParams */
    public static function health(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);

        try {
            \App\Database::pdo()->query('SELECT 1')->fetch();
            $db = 'ok';
        } catch (\Throwable) {
            $db = 'error';
        }

        Response::json([
            'status' => $db === 'ok' ? 'healthy' : 'unhealthy',
            'database' => $db,
            'timestamp' => gmdate('c'),
        ], $db === 'ok' ? 200 : 503);
    }

    /** @param array<string,string> $routeParams */
    public static function checkoutCreate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        try {
            $service = new CheckoutService();
            $result = $service->createSession($request->json);
            Response::json($result, 200);
        } catch (\Throwable $e) {
            self::logInternalError('checkoutCreate', $e);
            Response::json(['error' => 'Unable to create checkout session'], 400);
        }
    }

    /** @param array<string,string> $routeParams */
    public static function checkoutCreateForApp(Request $request, array $routeParams): void
    {
        if (!self::throttle($request)) {
            return;
        }

        $appSlug = (string) ($routeParams['app_slug'] ?? '');
        if ($appSlug === '') {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $payload = $request->json;
        $payload['appSlug'] = $appSlug;

        try {
            $service = new CheckoutService();
            $result = $service->createSession($payload);
            Response::json($result, 200);
        } catch (\Throwable $e) {
            self::logInternalError('checkoutCreateForApp', $e);
            Response::json(['error' => 'Unable to create checkout session'], 400);
        }
    }

    /** @param array<string,string> $routeParams */
    public static function checkoutStatus(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        $sessionId = trim((string) ($request->query['session_id'] ?? ''));
        if ($sessionId === '') {
            Response::json(['status' => 'error', 'message' => 'Missing session_id'], 400);
            return;
        }

        try {
            $service = new CheckoutService();
            $result = $service->getSessionStatus($sessionId);
            Response::json($result, 200);
        } catch (\Throwable $e) {
            self::logInternalError('checkoutStatus', $e);
            Response::json(['status' => 'error', 'message' => 'Unable to load checkout status'], 400);
        }
    }

    /** @param array<string,string> $routeParams */
    public static function checkoutStatusForApp(Request $request, array $routeParams): void
    {
        if (!self::throttle($request)) {
            return;
        }

        $appSlug = trim((string) ($routeParams['app_slug'] ?? ''));
        if ($appSlug === '') {
            Response::json(['status' => 'error', 'message' => 'App not found'], 404);
            return;
        }

        $sessionId = trim((string) ($request->query['session_id'] ?? ''));
        if ($sessionId === '') {
            Response::json(['status' => 'error', 'message' => 'Missing session_id'], 400);
            return;
        }

        try {
            $service = new CheckoutService();
            $result = $service->getSessionStatus($sessionId, $appSlug);
            Response::json($result, 200);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($message === 'Invalid appSlug') {
                Response::json(['status' => 'error', 'message' => 'App not found'], 404);
                return;
            }

            self::logInternalError('checkoutStatusForApp', $e);
            Response::json(['status' => 'error', 'message' => 'Unable to load checkout status'], 400);
        }
    }

    /** @param array<string,string> $routeParams */
    public static function stripeWebhook(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        try {
            $service = new StripeWebhookService();
            $result = $service->handle($request->rawBody, $request->header('stripe-signature'));
            Response::json($result, 200);
        } catch (\Throwable $e) {
            self::logInternalError('stripeWebhook', $e);
            Response::json(['error' => 'Webhook processing failed'], $e instanceof \DomainException ? 400 : 500);
        }
    }

    /** @param array<string,string> $routeParams */
    public static function contactSubmit(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        $service = new PublicFormService();
        $result = $service->submitContact($request->json, [
            'ip' => $request->ip,
            'userAgent' => $request->userAgent,
        ]);
        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);
        Response::json($result, $status);
    }

    /** @param array<string,string> $routeParams */
    public static function bookingSubmit(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        $service = new PublicFormService();
        $result = $service->submitBooking($request->json, [
            'ip' => $request->ip,
            'userAgent' => $request->userAgent,
        ]);
        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);
        Response::json($result, $status);
    }

    /** @param array<string,string> $routeParams */
    public static function newsletterSubmit(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        $service = new PublicFormService();
        $result = $service->submitNewsletter($request->json, [
            'ip' => $request->ip,
            'userAgent' => $request->userAgent,
        ]);
        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);
        Response::json($result, $status);
    }

    /** @param array<string,string> $routeParams */
    public static function publicFormsConfig(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        $service = new PublicFormService();
        Response::json($service->publicConfig(), 200);
    }

    /** @param array<string,string> $routeParams */
    public static function activate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        $key = (string) ($request->json['license_key'] ?? '');
        $domain = (string) ($request->json['domain'] ?? '');
        $hash = (string) ($request->json['installation_hash'] ?? '');

        if ($key === '' || $domain === '' || $hash === '') {
            Response::json(['status' => 'error', 'message' => 'Missing required fields'], 400);
            return;
        }
        if (!self::isValidLicenseKey($key) || !self::isValidDomain($domain) || !self::isValidInstallationHash($hash)) {
            Response::json(['status' => 'error', 'message' => 'Invalid field format'], 400);
            return;
        }

        try {
            $service = new LicenseService();
            $result = $service->activate($key, $domain, $hash, $request->json, $request->ip, $request->userAgent);
            Response::json($result, 200);
        } catch (\Throwable $e) {
            $code = $e->getMessage() === 'MAX_ACTIVATIONS_REACHED' ? 'MAX_ACTIVATIONS_REACHED' : 'ACTIVATION_FAILED';
            Response::json(['status' => 'error', 'message' => ClientError::message($e), 'code' => $code], 400);
        }
    }

    /** @param array<string,string> $routeParams */
    public static function activateForApp(Request $request, array $routeParams): void
    {
        if (!self::throttle($request)) {
            return;
        }

        $appSlug = (string) ($routeParams['app_slug'] ?? '');
        $key = (string) ($request->json['license_key'] ?? '');
        $domain = (string) ($request->json['domain'] ?? '');
        $hash = (string) ($request->json['installation_hash'] ?? '');

        if ($key === '' || $domain === '' || $hash === '') {
            Response::json(['status' => 'error', 'message' => 'Missing required fields'], 400);
            return;
        }
        if (!self::isValidLicenseKey($key) || !self::isValidDomain($domain) || !self::isValidInstallationHash($hash)) {
            Response::json(['status' => 'error', 'message' => 'Invalid field format'], 400);
            return;
        }

        try {
            $context = new AppContextService();
            $context->requireActiveApp($appSlug);
            $context->assertLicenseBelongsToApp($appSlug, $key);

            $service = new LicenseService();
            $result = $service->activate($key, $domain, $hash, $request->json, $request->ip, $request->userAgent);
            Response::json($result, 200);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($message === 'APP_NOT_FOUND') {
                Response::json(['status' => 'error', 'message' => 'App not found'], 404);
                return;
            }
            if ($message === 'LICENSE_APP_MISMATCH' || $message === 'LICENSE_NOT_FOUND') {
                Response::json(['status' => 'error', 'message' => 'License does not belong to app'], 404);
                return;
            }

            $code = $message === 'MAX_ACTIVATIONS_REACHED' ? 'MAX_ACTIVATIONS_REACHED' : 'ACTIVATION_FAILED';
            Response::json(['status' => 'error', 'message' => ClientError::message($e), 'code' => $code], 400);
        }
    }

    /** @param array<string,string> $routeParams */
    public static function verify(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        $key = (string) ($request->json['license_key'] ?? '');
        $hash = (string) ($request->json['installation_hash'] ?? '');
        if ($key === '' || $hash === '') {
            Response::json(['status' => 'error', 'message' => 'Missing required fields'], 400);
            return;
        }
        if (!self::isValidLicenseKey($key) || !self::isValidInstallationHash($hash)) {
            Response::json(['status' => 'error', 'message' => 'Invalid field format'], 400);
            return;
        }

        $metadata = is_array($request->json['metadata'] ?? null) ? $request->json['metadata'] : [];

        $service = new LicenseService();
        $result = $service->verify($key, $hash, $request->ip, $request->userAgent, $metadata);

        $statusCode = ($result['status'] === 'valid') ? 200 : 400;
        Response::json($result, $statusCode);
    }

    /** @param array<string,string> $routeParams */
    public static function verifyForApp(Request $request, array $routeParams): void
    {
        if (!self::throttle($request)) {
            return;
        }

        $appSlug = (string) ($routeParams['app_slug'] ?? '');
        $key = (string) ($request->json['license_key'] ?? '');
        $hash = (string) ($request->json['installation_hash'] ?? '');
        if ($key === '' || $hash === '') {
            Response::json(['status' => 'error', 'message' => 'Missing required fields'], 400);
            return;
        }
        if (!self::isValidLicenseKey($key) || !self::isValidInstallationHash($hash)) {
            Response::json(['status' => 'error', 'message' => 'Invalid field format'], 400);
            return;
        }

        $metadata = is_array($request->json['metadata'] ?? null) ? $request->json['metadata'] : [];

        try {
            $context = new AppContextService();
            $context->requireActiveApp($appSlug);
            $context->assertLicenseBelongsToApp($appSlug, $key);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($message === 'APP_NOT_FOUND') {
                Response::json(['status' => 'error', 'message' => 'App not found'], 404);
                return;
            }
            if ($message === 'LICENSE_APP_MISMATCH' || $message === 'LICENSE_NOT_FOUND') {
                Response::json(['status' => 'error', 'message' => 'License does not belong to app'], 404);
                return;
            }
            Response::json(['status' => 'error', 'message' => ClientError::message($e)], 400);
            return;
        }

        $service = new LicenseService();
        $result = $service->verify($key, $hash, $request->ip, $request->userAgent, $metadata);
        $statusCode = ($result['status'] === 'valid') ? 200 : 400;
        Response::json($result, $statusCode);
    }

    /** @param array<string,string> $routeParams */
    public static function deactivate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        $key = (string) ($request->json['license_key'] ?? '');
        $hash = (string) ($request->json['installation_hash'] ?? '');
        $reason = (string) ($request->json['reason'] ?? 'manual');
        if ($key === '' || $hash === '') {
            Response::json(['status' => 'error', 'message' => 'Missing required fields'], 400);
            return;
        }
        if (!self::isValidLicenseKey($key) || !self::isValidInstallationHash($hash) || strlen($reason) > 255) {
            Response::json(['status' => 'error', 'message' => 'Invalid field format'], 400);
            return;
        }

        $service = new LicenseService();
        $service->deactivate($key, $hash, $reason);
        Response::json(['status' => 'deactivated', 'message' => 'Muzete nyni aktivovat na nove domene'], 200);
    }

    /** @param array<string,string> $routeParams */
    public static function deactivateForApp(Request $request, array $routeParams): void
    {
        if (!self::throttle($request)) {
            return;
        }

        $appSlug = (string) ($routeParams['app_slug'] ?? '');
        $key = (string) ($request->json['license_key'] ?? '');
        $hash = (string) ($request->json['installation_hash'] ?? '');
        $reason = (string) ($request->json['reason'] ?? 'manual');
        if ($key === '' || $hash === '') {
            Response::json(['status' => 'error', 'message' => 'Missing required fields'], 400);
            return;
        }
        if (!self::isValidLicenseKey($key) || !self::isValidInstallationHash($hash) || strlen($reason) > 255) {
            Response::json(['status' => 'error', 'message' => 'Invalid field format'], 400);
            return;
        }

        try {
            $context = new AppContextService();
            $context->requireActiveApp($appSlug);
            $context->assertLicenseBelongsToApp($appSlug, $key);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($message === 'APP_NOT_FOUND') {
                Response::json(['status' => 'error', 'message' => 'App not found'], 404);
                return;
            }
            if ($message === 'LICENSE_APP_MISMATCH' || $message === 'LICENSE_NOT_FOUND') {
                Response::json(['status' => 'error', 'message' => 'License does not belong to app'], 404);
                return;
            }
            Response::json(['status' => 'error', 'message' => ClientError::message($e)], 400);
            return;
        }

        $service = new LicenseService();
        $service->deactivate($key, $hash, $reason);
        Response::json(['status' => 'deactivated', 'message' => 'Muzete nyni aktivovat na nove domene'], 200);
    }

    /** @param array<string,string> $routeParams */
    public static function adminListLicenses(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($request->query['per_page'] ?? 50)));
        $repo = new LicenseRepository();
        $data = $repo->listForAdmin([
            'type' => $request->query['type'] ?? null,
            'status' => $request->query['status'] ?? null,
            'search' => $request->query['search'] ?? null,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);

        Response::json([
            'data' => $data,
            'meta' => ['current_page' => $page, 'per_page' => $perPage],
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function adminLicenseDetail(Request $request, array $routeParams): void
    {
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $key = $routeParams['key'] ?? '';
        $repo = new LicenseRepository();
        $detail = $repo->detailForAdmin($key);
        if (!$detail) {
            Response::json(['error' => 'License not found'], 404);
            return;
        }

        Response::json($detail);
    }

    /** @param array<string,string> $routeParams */
    public static function adminLicenseUpdate(Request $request, array $routeParams): void
    {
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $key = $routeParams['key'] ?? '';
        $allowed = ['status', 'expires_at', 'revoke_reason'];
        $changes = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $request->json)) {
                $changes[$field] = $request->json[$field];
            }
        }

        if ($changes === []) {
            Response::json(['error' => 'No allowed fields provided'], 400);
            return;
        }
        if (isset($changes['status']) && !in_array((string) $changes['status'], ['pending', 'active', 'expired', 'revoked', 'refunded'], true)) {
            Response::json(['error' => 'Invalid status'], 400);
            return;
        }
        if (isset($changes['expires_at']) && $changes['expires_at'] !== null && strtotime((string) $changes['expires_at']) === false) {
            Response::json(['error' => 'Invalid expires_at format'], 400);
            return;
        }
        if (isset($changes['revoke_reason']) && $changes['revoke_reason'] !== null && strlen((string) $changes['revoke_reason']) > 2000) {
            Response::json(['error' => 'revoke_reason too long'], 400);
            return;
        }

        $repo = new LicenseRepository();
        $before = $repo->findByKey($key);
        if (!$before) {
            Response::json(['error' => 'License not found'], 404);
            return;
        }

        $repo->updateByKey($key, $changes);
        $after = $repo->findByKey($key);
        try {
            (new TenantOverageBillingService())->syncForTenant((new TenantContextService())->tenantIdForLicenseId((int) $before['id']));
        } catch (\Throwable $e) {
            error_log('Overage sync failed after adminLicenseUpdate: ' . $e->getMessage());
        }

        $audit = new AuditLogRepository();
        $audit->log(null, (int) $before['id'], 'license.updated', 'license', (int) $before['id'], $before, $after ?: [], $request->ip, $request->userAgent);

        Response::json(['success' => true, 'license' => $after ?: []]);
    }

    /** @param array<string,string> $routeParams */
    public static function adminStats(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $repo = new LicenseRepository();
        $stats = $repo->adminStats();
        Response::json($stats);
    }

    /** @param array<string,string> $routeParams */
    public static function adminStripeConfigGet(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $repo = new SettingsRepository();
        $keys = [
            'stripe_publishable_key',
            'stripe_secret_key',
            'stripe_webhook_secret',
            'stripe_price_project',
            'stripe_price_annual',
            'stripe_price_whitelabel',
        ];
        $dbValues = $repo->getMultiple($keys);

        $mask = static fn(?string $v): string => $v ? substr($v, 0, 8) . '***' . substr($v, -4) : '';

        Response::json([
            'publishable_key' => $mask($dbValues['stripe_publishable_key'] ?? \App\Support\Env::get('STRIPE_PUBLISHABLE_KEY')),
            'secret_key' => $mask($dbValues['stripe_secret_key'] ?? \App\Support\Env::get('STRIPE_SECRET_KEY')),
            'webhook_secret' => $mask($dbValues['stripe_webhook_secret'] ?? \App\Support\Env::get('STRIPE_WEBHOOK_SECRET')),
            'price_project' => $dbValues['stripe_price_project'] ?? \App\Support\Env::get('STRIPE_PRICE_PROJECT') ?? '',
            'price_annual' => $dbValues['stripe_price_annual'] ?? \App\Support\Env::get('STRIPE_PRICE_ANNUAL') ?? '',
            'price_whitelabel' => $dbValues['stripe_price_whitelabel'] ?? \App\Support\Env::get('STRIPE_PRICE_WHITELABEL') ?? '',
            'webhook_url' => rtrim((string) \App\Support\Env::get('APP_URL', ''), '/') . '/api/v1/webhook/stripe',
            'source' => $dbValues['stripe_secret_key'] ? 'database' : 'env',
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function adminStripeConfigUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $allowed = [
            'publishable_key' => 'stripe_publishable_key',
            'secret_key' => 'stripe_secret_key',
            'webhook_secret' => 'stripe_webhook_secret',
            'price_project' => 'stripe_price_project',
            'price_annual' => 'stripe_price_annual',
            'price_whitelabel' => 'stripe_price_whitelabel',
        ];

        $repo = new SettingsRepository();
        $updated = [];

        foreach ($allowed as $inputKey => $dbKey) {
            if (array_key_exists($inputKey, $request->json)) {
                $value = trim((string) $request->json[$inputKey]);
                if (str_contains($value, '***')) {
                    continue;
                }
                $repo->set($dbKey, $value !== '' ? $value : null);
                $updated[] = $inputKey;
            }
        }

        Response::json(['success' => true, 'updated' => $updated]);
    }

    /** @param array<string,string> $routeParams */
    public static function adminStripeTest(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $repo = new SettingsRepository();
        $secretKey = $repo->get('stripe_secret_key') ?? \App\Support\Env::get('STRIPE_SECRET_KEY', '');

        if ($secretKey === '') {
            Response::json(['success' => false, 'error' => 'Stripe secret key not configured'], 400);
            return;
        }

        $ch = curl_init('https://api.stripe.com/v1/account');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secretKey],
        ]);

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $resp === false) {
            Response::json(['success' => false, 'error' => 'Stripe API error', 'http_code' => $httpCode], 400);
            return;
        }

        $data = json_decode((string) $resp, true);
        Response::json([
            'success' => true,
            'account_id' => $data['id'] ?? null,
            'account_name' => $data['settings']['dashboard']['display_name'] ?? $data['business_profile']['name'] ?? null,
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function adminMailSettingsGet(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $mail = new \App\Services\MailService();
        Response::json($mail->getConfig());
    }

    /** @param array<string,string> $routeParams */
    public static function adminMailSettingsUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $allowed = [
            'mail_enabled',
            'mail_host',
            'mail_port',
            'mail_username',
            'mail_password',
            'mail_encryption',
            'mail_from_address',
            'mail_from_name',
        ];

        $repo = new SettingsRepository();
        $updated = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $request->json)) {
                $value = trim((string) $request->json[$key]);
                // Skip empty password (keep existing)
                if ($key === 'mail_password' && $value === '') {
                    continue;
                }
                $repo->set($key, $value !== '' ? $value : null);
                $mirrorMap = [
                    'mail_host' => 'smtp_host',
                    'mail_port' => 'smtp_port',
                    'mail_username' => 'smtp_username',
                    'mail_password' => 'smtp_password',
                    'mail_encryption' => 'smtp_encryption',
                    'mail_from_address' => 'smtp_from_email',
                    'mail_from_name' => 'smtp_from_name',
                ];
                if (isset($mirrorMap[$key])) {
                    $repo->set($mirrorMap[$key], $value !== '' ? $value : null);
                }
                $updated[] = $key;
            }
        }

        Response::json(['success' => true, 'updated' => $updated]);
    }

    /** @param array<string,string> $routeParams */
    public static function adminMailTemplatesGet(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $mail = new \App\Services\MailService();
        Response::json([
            'templates' => $mail->getTemplates(),
            'defaults' => $mail->getDefaultTemplates(),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function adminMailTemplatesUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $allowed = ['mail_license_subject', 'mail_license_body'];
        $repo = new SettingsRepository();
        $updated = [];

        $templates = $request->json['templates'] ?? $request->json;

        foreach ($allowed as $key) {
            if (array_key_exists($key, $templates)) {
                $value = (string) $templates[$key];
                $repo->set($key, $value !== '' ? $value : null);
                $updated[] = $key;
            }
        }

        Response::json(['success' => true, 'updated' => $updated]);
    }

    /** @param array<string,string> $routeParams */
    public static function adminMailTest(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $toEmail = trim((string) ($request->json['email'] ?? ''));
        if ($toEmail === '') {
            Response::json(['success' => false, 'error' => 'Email je povinny'], 400);
            return;
        }

        $mail = new \App\Services\MailService();
        $result = $mail->sendTestEmail($toEmail);
        Response::json($result);
    }

    /** @param array<string,string> $routeParams */
    public static function adminCreateLicense(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::adminGuard($request) || !self::throttle($request)) {
            return;
        }

        $productName = trim((string) ($request->json['product_name'] ?? ''));
        $productSlug = trim((string) ($request->json['product_slug'] ?? ''));
        $customerEmail = trim((string) ($request->json['customer_email'] ?? ''));
        $licenseType = trim((string) ($request->json['license_type'] ?? 'lifetime'));
        $appSlug = trim((string) ($request->json['app_slug'] ?? ''));

        if ($customerEmail === '') {
            Response::json(['error' => 'customer_email is required'], 400);
            return;
        }

        $allowedTypes = ['lifetime', 'annual', 'monthly', 'trial', 'whitelabel'];
        if (!in_array($licenseType, $allowedTypes, true)) {
            Response::json(['error' => 'Invalid license_type'], 400);
            return;
        }

        $generator = new LicenseKeyGenerator();
        $licenseKey = $generator->generate($licenseType, $productName, $productSlug);
        $context = new AppContextRepository();
        $resolvedApp = $appSlug !== '' ? (new AppRepository())->findBySlug($appSlug) : null;
        $appId = is_array($resolvedApp) ? (int) $resolvedApp['id'] : $context->resolveAppIdForProductSlug($productSlug !== '' ? $productSlug : null);
        if ($appSlug === '' && $productSlug !== '' && $appId <= 0 && $context->countActiveAppsForProductSlug($productSlug) !== 0) {
            Response::json(['error' => 'app_slug is required for this product_slug'], 422);
            return;
        }

        $expiresAt = match ($licenseType) {
            'monthly' => date('Y-m-d H:i:s', strtotime('+1 month')),
            'annual' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'trial' => date('Y-m-d H:i:s', strtotime('+14 days')),
            default => null,
        };

        $maxActivations = 3;
        $tenantId = $appId > 0
            ? (new TenantContextService())->tenantIdForAppId($appId)
            : (new TenantContextService())->defaultTenantId();
        try {
            (new TenantBillingService())->assertCanIssueActiveLicense($tenantId);
        } catch (\RuntimeException $e) {
            Response::json(['error' => ClientError::message($e)], 422);
            return;
        }

        $pdo = \App\Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO licenses (tenant_id, app_id, license_key, license_type, product_slug, product_name, customer_email, status, expires_at, max_activations)
             VALUES (:tenant_id, :app_id, :license_key, :license_type, :product_slug, :product_name, :customer_email, :status, :expires_at, :max_activations)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'license_key' => $licenseKey,
            'license_type' => $licenseType,
            'product_slug' => $productSlug !== '' ? $productSlug : null,
            'product_name' => $productName !== '' ? $productName : null,
            'customer_email' => $customerEmail,
            'status' => 'active',
            'expires_at' => $expiresAt,
            'max_activations' => $maxActivations,
        ]);
        try {
            (new TenantOverageBillingService())->syncForTenant($tenantId);
        } catch (\Throwable $e) {
            error_log('Overage sync failed after adminCreateLicense: ' . $e->getMessage());
        }

        Response::json([
            'success' => true,
            'app_slug' => $appSlug !== '' ? $appSlug : null,
            'license_key' => $licenseKey,
            'product_slug' => $productSlug,
            'product_name' => $productName,
            'license_type' => $licenseType,
            'expires_at' => $expiresAt,
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function products(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        $repo = new ProductRepository();
        $currency = CurrencyCatalog::normalize((string) ($request->query['currency'] ?? ''));

        Response::json([
            'currency' => $currency,
            'products' => $repo->listActiveForProductIds(null, $currency),
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function productsForApp(Request $request, array $routeParams): void
    {
        if (!self::throttle($request)) {
            return;
        }

        $appSlug = (string) ($routeParams['app_slug'] ?? '');
        $app = (new AppRepository())->findBySlug($appSlug);
        if ($app === null || (int) ($app['is_active'] ?? 0) !== 1) {
            Response::json(['error' => 'App not found'], 404);
            return;
        }

        $productIds = (new AppProductRepository())->listProductIdsForApp((int) $app['id']);
        $currency = CurrencyCatalog::normalize((string) ($request->query['currency'] ?? ''));
        $products = (new ProductRepository())->listActiveForProductIds($productIds, $currency);

        Response::json([
            'app' => [
                'slug' => $app['slug'],
                'name' => $app['name'],
            ],
            'currency' => $currency,
            'products' => $products,
        ]);
    }

    /** @param array<string,string> $routeParams */
    public static function customerLicenses(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::throttle($request)) {
            return;
        }

        // Zabezpečení: vyžaduje admin bearer token
        if (!self::adminGuard($request)) {
            return;
        }

        $email = trim((string) ($request->query['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['error' => 'Invalid email'], 400);
            return;
        }

        $pdo = \App\Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT license_key, license_type, status, expires_at, current_activations, max_activations, created_at
             FROM licenses WHERE customer_email = :email ORDER BY created_at DESC'
        );
        $stmt->execute(['email' => $email]);
        $rows = $stmt->fetchAll() ?: [];

        Response::json(['licenses' => $rows]);
    }

    private static function adminGuard(Request $request): bool
    {
        $auth = new AuthService();
        $tokenLabel = $auth->authorizedAdminTokenLabel($request);
        if ($tokenLabel === null) {
            (new AuditLogRepository())->log(
                null,
                null,
                'admin_api_auth_failed',
                'admin_api',
                null,
                [],
                [
                    'path' => $request->path,
                    'method' => $request->method,
                ],
                $request->ip,
                $request->userAgent
            );
            Response::json(['error' => 'Unauthorized'], 401);
            return false;
        }

        if ($request->method !== 'GET') {
            (new AuditLogRepository())->log(
                null,
                null,
                'admin_api_auth',
                'admin_api',
                null,
                [],
                [
                    'path' => $request->path,
                    'method' => $request->method,
                    'token_label' => $tokenLabel,
                ],
                $request->ip,
                $request->userAgent
            );
        }

        return true;
    }

    private static function throttle(Request $request): bool
    {
        $rl = new RateLimiter();
        [$endpoint, $limit] = self::resolveRateLimit($request);
        if (!$rl->allow($request->ip, $endpoint, $limit)) {
            Response::json(['error' => 'Too many requests'], 429);
            return false;
        }

        return true;
    }

    /** @return array{0:string,1:int} */
    private static function resolveRateLimit(Request $request): array
    {
        $path = $request->path;

        if ($path === '/api/v1/health') {
            return ['health', 300];
        }

        if ($path === '/api/v1/webhook/stripe') {
            return ['stripe_webhook', 300];
        }

        if (str_ends_with($path, '/checkout/create') || $path === '/api/v1/checkout/create') {
            return ['checkout_create', 20];
        }

        if (str_ends_with($path, '/checkout/status') || $path === '/api/v1/checkout/status') {
            return ['checkout_status', 120];
        }

        if ($path === '/api/v1/contact/submit') {
            return ['public_contact_submit', 20];
        }

        if ($path === '/api/v1/booking/submit') {
            return ['public_booking_submit', 20];
        }

        if ($path === '/api/v1/newsletter/submit') {
            return ['public_newsletter_submit', 20];
        }

        if ($path === '/api/v1/public/forms/config') {
            return ['public_forms_config', 120];
        }

        if (str_contains($path, '/verify')) {
            return ['license_verify', 180];
        }

        if (str_contains($path, '/activate')) {
            return ['license_activate', 30];
        }

        if (str_contains($path, '/deactivate')) {
            return ['license_deactivate', 30];
        }

        if (str_starts_with($path, '/api/v1/admin/')) {
            return ['admin_api', 60];
        }

        return [$path, 100];
    }

    private static function isValidLicenseKey(string $key): bool
    {
        return preg_match('/^(LCMS|FLOX|THOS|LOFT)-[A-Z]{4}-(?:[A-Z0-9]{4}-){4}[A-Z0-9]{4}$/', $key) === 1;
    }

    private static function isValidInstallationHash(string $hash): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{8,64}$/', $hash) === 1;
    }

    private static function isValidDomain(string $domain): bool
    {
        if (strlen($domain) > 255 || str_contains($domain, '://')) {
            return false;
        }

        return preg_match('/^(?:[a-zA-Z0-9-]+\\.)+[a-zA-Z]{2,}$/', $domain) === 1;
    }

    private static function logInternalError(string $context, \Throwable $e): void
    {
        error_log(sprintf('[%s] %s', $context, $e->getMessage()));
    }
}
