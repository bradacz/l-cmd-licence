<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Http\Api;
use App\Http\AdminWeb;
use App\Http\CustomerApi;
use App\Http\Request;
use App\Http\Response;
use App\Router;
use App\Support\Env;
use App\Services\CsrfService;

if (session_status() !== PHP_SESSION_ACTIVE) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $isCustomerApi = str_starts_with((string) $requestPath, '/customer-api');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', ($isHttps && $isCustomerApi) ? 'None' : 'Lax');
    if (PHP_VERSION_ID < 80400) {
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
    }

    session_start();
}

$appDebug = Env::bool('APP_DEBUG', false);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-XSS-Protection: 0');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
if (!$appDebug) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

$request = Request::capture();

// CORS - povoleno pro prodejni weby a customer portal, webhook bez CORS
$isWebhook = str_starts_with($request->path, '/api/v1/webhook/');
if (
    !$isWebhook
    && (str_starts_with($request->path, '/api/') || str_starts_with($request->path, '/customer-api'))
) {
    $normalizeOrigin = static function (string $value): string {
        return rtrim(strtolower(trim($value)), '/');
    };

    $allowedOrigins = array_values(array_filter(array_map(
        $normalizeOrigin,
        explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))
    )));
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if (in_array($normalizeOrigin($origin), $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: POST, GET, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

    if ($request->method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

if (
    str_starts_with($request->path, '/customer-api')
    && in_array($request->method, ['POST', 'PATCH', 'DELETE'], true)
    && $request->path !== '/customer-api/csrf'
) {
    if (!CsrfService::verify($request->header('x-csrf-token'))) {
        Response::json(['error' => 'CSRF validation failed'], 403);
        exit;
    }
}

$router = new Router();

$router->add('GET', '/health', [Api::class, 'health']);

$router->add('POST', '/customer-api/auth/activate', [CustomerApi::class, 'activate']);
$router->add('POST', '/customer-api/auth/set-password', [CustomerApi::class, 'setPassword']);
$router->add('POST', '/customer-api/auth/register', [CustomerApi::class, 'register']);
$router->add('POST', '/customer-api/auth/forgot-password', [CustomerApi::class, 'forgotPassword']);
$router->add('POST', '/customer-api/auth/login', [CustomerApi::class, 'login']);
$router->add('POST', '/customer-api/auth/logout', [CustomerApi::class, 'logout']);
$router->add('POST', '/customer-api/auth/reset-password', [CustomerApi::class, 'resetPassword']);
$router->add('POST', '/customer-api/auth/switch-tenant', [CustomerApi::class, 'switchTenant']);
$router->add('GET', '/customer-api/csrf', [CustomerApi::class, 'csrf']);
$router->add('GET', '/customer-api/me', [CustomerApi::class, 'me']);
$router->add('GET', '/customer-api/memberships', [CustomerApi::class, 'memberships']);
$router->add('GET', '/customer-api/tenant', [CustomerApi::class, 'tenantProfile']);
$router->add('PATCH', '/customer-api/tenant', [CustomerApi::class, 'tenantProfileUpdate']);
$router->add('GET', '/customer-api/billing/plan', [CustomerApi::class, 'billingPlan']);
$router->add('GET', '/customer-api/analytics/summary', [CustomerApi::class, 'analyticsSummary']);
$router->add('GET', '/customer-api/analytics/advanced', [CustomerApi::class, 'analyticsAdvanced']);
$router->add('GET', '/customer-api/integrations/stripe', [CustomerApi::class, 'stripeConfig']);
$router->add('PATCH', '/customer-api/integrations/stripe', [CustomerApi::class, 'stripeConfigUpdate']);
$router->add('POST', '/customer-api/integrations/stripe/test', [CustomerApi::class, 'stripeConfigTest']);
$router->add('GET', '/customer-api/integrations/smtp', [CustomerApi::class, 'smtpConfig']);
$router->add('PATCH', '/customer-api/integrations/smtp', [CustomerApi::class, 'smtpConfigUpdate']);
$router->add('POST', '/customer-api/integrations/smtp/test', [CustomerApi::class, 'smtpConfigTest']);
$router->add('GET', '/customer-api/integrations/google-analytics', [CustomerApi::class, 'googleAnalyticsConfigs']);
$router->add('PATCH', '/customer-api/integrations/google-analytics', [CustomerApi::class, 'googleAnalyticsConfigsUpdate']);
$router->add('GET', '/customer-api/integrations/ai-insights', [CustomerApi::class, 'aiInsightsConfig']);
$router->add('PATCH', '/customer-api/integrations/ai-insights', [CustomerApi::class, 'aiInsightsConfigUpdate']);
$router->add('POST', '/customer-api/integrations/ai-insights/run', [CustomerApi::class, 'aiInsightsRun']);
$router->add('GET', '/customer-api/integrations/ai-assistant', [CustomerApi::class, 'aiAssistantConfig']);
$router->add('PATCH', '/customer-api/integrations/ai-assistant', [CustomerApi::class, 'aiAssistantConfigUpdate']);
$router->add('POST', '/customer-api/integrations/ai-assistant/run', [CustomerApi::class, 'aiAssistantRun']);
$router->add('GET', '/customer-api/apps', [CustomerApi::class, 'appsList']);
$router->add('POST', '/customer-api/apps', [CustomerApi::class, 'appsCreate']);
$router->add('GET', '/customer-api/apps/{id}', [CustomerApi::class, 'appsDetail']);
$router->add('PATCH', '/customer-api/apps/{id}', [CustomerApi::class, 'appsUpdate']);
$router->add('POST', '/customer-api/apps/{id}/activate', [CustomerApi::class, 'appsActivate']);
$router->add('POST', '/customer-api/apps/{id}/deactivate', [CustomerApi::class, 'appsDeactivate']);
$router->add('GET', '/customer-api/apps/{id}/products', [CustomerApi::class, 'appProductsDetail']);
$router->add('PATCH', '/customer-api/apps/{id}/products', [CustomerApi::class, 'appProductsUpdate']);
$router->add('GET', '/customer-api/apps/{id}/currencies', [CustomerApi::class, 'appCurrenciesDetail']);
$router->add('PATCH', '/customer-api/apps/{id}/currencies', [CustomerApi::class, 'appCurrenciesUpdate']);
$router->add('GET', '/customer-api/apps/{id}/notifications', [CustomerApi::class, 'appNotificationsDetail']);
$router->add('PATCH', '/customer-api/apps/{id}/notifications', [CustomerApi::class, 'appNotificationsUpdate']);
$router->add('GET', '/customer-api/apps/{id}/keys', [CustomerApi::class, 'appKeysList']);
$router->add('POST', '/customer-api/apps/{id}/keys', [CustomerApi::class, 'appKeysCreate']);
$router->add('POST', '/customer-api/apps/{id}/keys/{keyId}/rotate', [CustomerApi::class, 'appKeyRotate']);
$router->add('POST', '/customer-api/apps/{id}/keys/{keyId}/deactivate', [CustomerApi::class, 'appKeyDeactivate']);
$router->add('GET', '/customer-api/apps/{id}/google-analytics', [CustomerApi::class, 'googleAnalyticsDetail']);
$router->add('PATCH', '/customer-api/apps/{id}/google-analytics', [CustomerApi::class, 'googleAnalyticsUpdate']);
$router->add('GET', '/customer-api/apps/{id}/templates', [CustomerApi::class, 'appTemplatesList']);
$router->add('GET', '/customer-api/apps/{id}/templates/{template_key}', [CustomerApi::class, 'appTemplateDetail']);
$router->add('PATCH', '/customer-api/apps/{id}/templates/{template_key}', [CustomerApi::class, 'appTemplateUpdate']);
$router->add('POST', '/customer-api/apps/{id}/templates/{template_key}/test', [CustomerApi::class, 'appTemplateTest']);
$router->add('GET', '/customer-api/apps/{id}/policies', [CustomerApi::class, 'appPoliciesDetail']);
$router->add('PATCH', '/customer-api/apps/{id}/policies', [CustomerApi::class, 'appPoliciesUpdate']);
$router->add('GET', '/customer-api/apps/{id}/integration-guide', [CustomerApi::class, 'appIntegrationGuide']);
$router->add('GET', '/customer-api/apps/{id}/offline-public-key', [CustomerApi::class, 'appOfflinePublicKey']);
$router->add('GET', '/customer-api/products', [CustomerApi::class, 'productsList']);
$router->add('POST', '/customer-api/products', [CustomerApi::class, 'productsCreate']);
$router->add('GET', '/customer-api/products/{id}', [CustomerApi::class, 'productsDetail']);
$router->add('PATCH', '/customer-api/products/{id}', [CustomerApi::class, 'productsUpdate']);
$router->add('GET', '/customer-api/products/{id}/tariffs', [CustomerApi::class, 'productTariffsList']);
$router->add('POST', '/customer-api/products/{id}/tariffs', [CustomerApi::class, 'productTariffsCreate']);
$router->add('GET', '/customer-api/products/{id}/policies', [CustomerApi::class, 'productPoliciesDetail']);
$router->add('PATCH', '/customer-api/products/{id}/policies', [CustomerApi::class, 'productPoliciesUpdate']);
$router->add('GET', '/customer-api/tariffs/{id}', [CustomerApi::class, 'tariffDetail']);
$router->add('PATCH', '/customer-api/tariffs/{id}', [CustomerApi::class, 'tariffUpdate']);
$router->add('GET', '/customer-api/tariffs/{id}/policies', [CustomerApi::class, 'tariffPoliciesDetail']);
$router->add('PATCH', '/customer-api/tariffs/{id}/policies', [CustomerApi::class, 'tariffPoliciesUpdate']);
$router->add('GET', '/customer-api/licenses', [CustomerApi::class, 'licensesList']);
$router->add('GET', '/customer-api/licenses/{key}', [CustomerApi::class, 'licenseDetail']);
$router->add('GET', '/customer-api/licenses/{key}/activations', [CustomerApi::class, 'licenseActivations']);
$router->add('GET', '/customer-api/licenses/{key}/verifications', [CustomerApi::class, 'licenseVerifications']);
$router->add('GET', '/customer-api/webhooks', [CustomerApi::class, 'webhooksList']);

$router->add('POST', '/api/v1/checkout/create', [Api::class, 'checkoutCreate']);
$router->add('POST', '/api/v1/apps/{app_slug}/checkout/create', [Api::class, 'checkoutCreateForApp']);
$router->add('GET', '/api/v1/checkout/status', [Api::class, 'checkoutStatus']);
$router->add('GET', '/api/v1/apps/{app_slug}/checkout/status', [Api::class, 'checkoutStatusForApp']);
$router->add('POST', '/api/v1/contact/submit', [Api::class, 'contactSubmit']);
$router->add('POST', '/api/v1/booking/submit', [Api::class, 'bookingSubmit']);
$router->add('POST', '/api/v1/newsletter/submit', [Api::class, 'newsletterSubmit']);
$router->add('GET', '/api/v1/public/forms/config', [Api::class, 'publicFormsConfig']);
$router->add('POST', '/api/v1/webhook/stripe', [Api::class, 'stripeWebhook']);
$router->add('POST', '/api/v1/license/activate', [Api::class, 'activate']);
$router->add('POST', '/api/v1/apps/{app_slug}/license/activate', [Api::class, 'activateForApp']);
$router->add('POST', '/api/v1/license/verify', [Api::class, 'verify']);
$router->add('POST', '/api/v1/apps/{app_slug}/license/verify', [Api::class, 'verifyForApp']);
$router->add('POST', '/api/v1/license/deactivate', [Api::class, 'deactivate']);
$router->add('POST', '/api/v1/apps/{app_slug}/license/deactivate', [Api::class, 'deactivateForApp']);

$router->add('GET', '/api/v1/products', [Api::class, 'products']);
$router->add('GET', '/api/v1/apps/{app_slug}/products', [Api::class, 'productsForApp']);
$router->add('GET', '/api/v1/customer/licenses', [Api::class, 'customerLicenses']);

$router->add('POST', '/api/v1/admin/licenses', [Api::class, 'adminCreateLicense']);
$router->add('GET', '/api/v1/admin/licenses', [Api::class, 'adminListLicenses']);
$router->add('GET', '/api/v1/admin/licenses/{key}', [Api::class, 'adminLicenseDetail']);
$router->add('PATCH', '/api/v1/admin/licenses/{key}', [Api::class, 'adminLicenseUpdate']);
$router->add('GET', '/api/v1/admin/stats', [Api::class, 'adminStats']);
$router->add('GET', '/api/v1/admin/stripe-config', [Api::class, 'adminStripeConfigGet']);
$router->add('PUT', '/api/v1/admin/stripe-config', [Api::class, 'adminStripeConfigUpdate']);
$router->add('POST', '/api/v1/admin/stripe-test', [Api::class, 'adminStripeTest']);
$router->add('GET', '/api/v1/admin/mail-settings', [Api::class, 'adminMailSettingsGet']);
$router->add('PUT', '/api/v1/admin/mail-settings', [Api::class, 'adminMailSettingsUpdate']);
$router->add('POST', '/api/v1/admin/mail-test', [Api::class, 'adminMailTest']);
$router->add('GET', '/api/v1/admin/mail-templates', [Api::class, 'adminMailTemplatesGet']);
$router->add('PUT', '/api/v1/admin/mail-templates', [Api::class, 'adminMailTemplatesUpdate']);

$router->add('GET', '/admin/login', [AdminWeb::class, 'loginPage']);
$router->add('POST', '/admin/login', [AdminWeb::class, 'loginSubmit']);
$router->add('POST', '/admin/logout', [AdminWeb::class, 'logout']);
$router->add('GET', '/admin', [AdminWeb::class, 'dashboard']);
$router->add('GET', '/admin/about', [AdminWeb::class, 'aboutPage']);
$router->add('GET', '/admin/licenses', [AdminWeb::class, 'licenses']);
$router->add('GET', '/admin/licenses/{key}', [AdminWeb::class, 'licenseDetail']);
$router->add('POST', '/admin/licenses/{key}', [AdminWeb::class, 'licenseUpdate']);

$router->add('GET', '/admin/products', [AdminWeb::class, 'productsPage']);
$router->add('POST', '/admin/products', [AdminWeb::class, 'productsCreate']);
$router->add('GET', '/admin/products/{id}', [AdminWeb::class, 'productDetail']);
$router->add('POST', '/admin/products/{id}', [AdminWeb::class, 'productUpdate']);

$router->add('GET', '/admin/apps', [AdminWeb::class, 'appsPage']);
$router->add('POST', '/admin/apps', [AdminWeb::class, 'appCreate']);
$router->add('GET', '/admin/apps/{id}', [AdminWeb::class, 'appDetail']);
$router->add('POST', '/admin/apps/{id}', [AdminWeb::class, 'appUpdate']);
$router->add('POST', '/admin/apps/{id}/keys', [AdminWeb::class, 'appKeyCreate']);
$router->add('POST', '/admin/apps/{id}/products', [AdminWeb::class, 'appProductAssign']);
$router->add('POST', '/admin/apps/{id}/notifications', [AdminWeb::class, 'appNotificationsUpdate']);
$router->add('POST', '/admin/apps/{id}/email-templates', [AdminWeb::class, 'appEmailTemplatesUpdate']);

$router->add('GET', '/admin/policies', [AdminWeb::class, 'policiesPage']);
$router->add('POST', '/admin/policies/system', [AdminWeb::class, 'policySystemUpdate']);
$router->add('POST', '/admin/policies/app', [AdminWeb::class, 'policyAppUpdate']);

$router->add('GET', '/admin/operations', [AdminWeb::class, 'operationsPage']);
$router->add('GET', '/admin/webhooks', [AdminWeb::class, 'webhooksPage']);
 $router->add('GET', '/admin/integrations/google-analytics', [AdminWeb::class, 'googleAnalyticsPage']);
 $router->add('POST', '/admin/integrations/google-analytics', [AdminWeb::class, 'googleAnalyticsSave']);
 $router->add('GET', '/admin/integrations/ai-insights', [AdminWeb::class, 'aiInsightsPage']);
 $router->add('POST', '/admin/integrations/ai-insights/settings', [AdminWeb::class, 'aiInsightsSettingsSave']);
 $router->add('POST', '/admin/integrations/ai-insights/run', [AdminWeb::class, 'aiInsightsRun']);
 $router->add('GET', '/admin/integrations/ai-assistant', [AdminWeb::class, 'aiAssistantPage']);
 $router->add('POST', '/admin/integrations/ai-assistant/settings', [AdminWeb::class, 'aiAssistantSettingsSave']);
 $router->add('POST', '/admin/integrations/ai-assistant/run', [AdminWeb::class, 'aiAssistantRun']);

$router->add('GET', '/admin/settings/smtp', [AdminWeb::class, 'smtpSettings']);
$router->add('POST', '/admin/settings/smtp', [AdminWeb::class, 'smtpSettingsSubmit']);
$router->add('GET', '/admin/settings/email-templates', [AdminWeb::class, 'emailTemplatesPage']);
$router->add('POST', '/admin/settings/email-templates', [AdminWeb::class, 'emailTemplatesSubmit']);

$router->add('GET', '/admin/audit-log', [AdminWeb::class, 'auditLogPage']);
$router->add('GET', '/admin/tenants', [AdminWeb::class, 'tenantsPage']);
$router->add('GET', '/admin/tenants/{id}', [AdminWeb::class, 'tenantDetailPage']);
$router->add('POST', '/admin/tenants/{id}/billing', [AdminWeb::class, 'tenantBillingUpdate']);
$router->add('POST', '/admin/tenants/{id}/access-keys', [AdminWeb::class, 'tenantAccessKeyIssue']);

$match = $router->match($request);
if (!$match) {
    if (str_starts_with($request->path, '/admin')) {
        Response::html('<h1>Not Found</h1>', 404);
    } else {
        Response::json(['error' => 'Not Found'], 404);
    }
    exit;
}

try {
    ($match['handler'])($request, $match['params']);
} catch (Throwable $e) {
    \App\Http\ClientError::respond($e, str_starts_with($request->path, '/admin'));
}
