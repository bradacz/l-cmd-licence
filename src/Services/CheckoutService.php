<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppProductRepository;
use App\Repositories\AppRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TenantStripeConnectionRepository;
use App\Repositories\TenantCheckoutOrderRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProductRepository;
use App\Support\AppCheckoutUrlConfig;
use App\Support\Env;
use App\Support\Json;
use App\Support\TenantPlanCatalog;
use RuntimeException;

final class CheckoutService
{
    /** @param array<string,mixed> $payload
      * @return array<string,mixed> */
    public function createSession(array $payload): array
    {
        $priceId = trim((string) ($payload['priceId'] ?? ''));
        $licenseType = trim((string) ($payload['licenseType'] ?? ''));
        $customerEmail = trim((string) ($payload['customerEmail'] ?? ''));
        $appSlug = trim((string) ($payload['appSlug'] ?? ''));

        if ($priceId === '' || $licenseType === '' || $customerEmail === '') {
            throw new RuntimeException('Missing required fields');
        }

        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid customerEmail');
        }

        $allowedTypes = $this->getAllowedLicenseTypes();
        if (!in_array($licenseType, $allowedTypes, true)) {
            throw new RuntimeException('Invalid licenseType');
        }

        $productRepo = new ProductRepository();
        $tariff = $productRepo->findActiveTariffByPriceId($priceId);
        if ($tariff === null) {
            throw new RuntimeException('Invalid priceId');
        }
        if ((string) $tariff['license_type'] !== $licenseType) {
            throw new RuntimeException('priceId does not match licenseType');
        }

        $purchaseKind = $this->normalizePurchaseKind((string) ($tariff['purchase_kind'] ?? 'license'));
        $targetPlanKey = $this->resolveTargetPlanKey($purchaseKind, $tariff);

        $app = null;
        if ($appSlug !== '') {
            $app = (new AppRepository())->findBySlug($appSlug);
            if ($app === null || (int) ($app['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('Invalid appSlug');
            }

            $allowedProductIds = (new AppProductRepository())->listProductIdsForApp((int) $app['id']);
            if ($allowedProductIds !== [] && !in_array((int) $tariff['product_id'], $allowedProductIds, true)) {
                throw new RuntimeException('priceId is not assigned to app');
            }
        }

        if ($purchaseKind === 'tenant_plan' && $app === null) {
            throw new RuntimeException('tenant_plan checkout requires appSlug');
        }

        $secret = $this->resolveStripeSecret($purchaseKind === 'tenant_plan' ? null : $app);
        if ($secret === '') {
            throw new RuntimeException('Stripe is not configured');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required for Stripe API calls');
        }

        [$successUrl, $cancelUrl] = $this->resolveReturnUrls($payload, $app);

        $form = [
            'mode' => in_array($licenseType, ['monthly', 'annual'], true) ? 'subscription' : 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $customerEmail,
            'billing_address_collection' => 'required',
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => '1',
            'metadata[license_type]' => $licenseType,
            'metadata[product_slug]' => (string) $tariff['product_slug'],
            'metadata[product_name]' => (string) $tariff['product_name'],
            'metadata[app_slug]' => $appSlug,
            'metadata[tenant_id]' => isset($app['tenant_id']) ? (string) (int) $app['tenant_id'] : '',
            'metadata[purchase_kind]' => $purchaseKind,
            'metadata[target_plan_key]' => $targetPlanKey ?? '',
            'metadata[source]' => (string) ($payload['metadata']['source'] ?? 'license-server'),
        ];

        if ($form['mode'] === 'payment') {
            $form['customer_creation'] = 'always';
            $form['invoice_creation[enabled]'] = 'true';
        }

        if (!empty($payload['metadata']) && is_array($payload['metadata'])) {
            $form = \App\Support\CheckoutMetadata::append($form, $payload['metadata']);
        }

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secret,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query($form),
        ]);

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $curlErr !== '') {
            throw new RuntimeException('Stripe request failed: ' . $curlErr);
        }

        $decoded = Json::decodeObject($resp);
        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = (string) ($decoded['error']['message'] ?? ('Stripe error HTTP ' . $httpCode));
            throw new RuntimeException($msg);
        }

        $sessionId = (string) ($decoded['id'] ?? '');
        $url = (string) ($decoded['url'] ?? '');

        if ($sessionId === '' || $url === '') {
            throw new RuntimeException('Stripe response missing id/url');
        }

        if ($purchaseKind === 'tenant_plan' && $app !== null) {
            $orderId = (new TenantCheckoutOrderRepository())->create([
                'tenant_id' => $app['tenant_id'] ?? null,
                'app_id' => (int) $app['id'],
                'product_id' => (int) $tariff['product_id'],
                'tariff_id' => (int) $tariff['id'],
                'stripe_session_id' => $sessionId,
                'stripe_price_id' => $priceId,
                'purchase_kind' => $purchaseKind,
                'target_plan_key' => $targetPlanKey,
                'customer_email' => $customerEmail,
                'status' => 'checkout_created',
            ]);
            (new AuditLogRepository())->log(
                null,
                null,
                'tenant.checkout.created',
                'tenant_checkout_order',
                $orderId,
                [],
                [
                    'app_slug' => (string) $app['slug'],
                    'stripe_session_id' => $sessionId,
                    'stripe_price_id' => $priceId,
                    'target_plan_key' => $targetPlanKey,
                    'customer_email' => mb_strtolower($customerEmail),
                ],
                '0.0.0.0',
                'checkout-service'
            );
        }

        return [
            'sessionId' => $sessionId,
            'url' => $url,
        ];
    }

    /** @param array<string,mixed> $payload
      * @param array<string,mixed>|null $app
      * @return array{0:string,1:string} */
    private function resolveReturnUrls(array $payload, ?array $app): array
    {
        if (is_array($app)) {
            $config = AppCheckoutUrlConfig::normalize(
                (string) ($app['public_base_url'] ?? ''),
                (string) ($app['checkout_success_url'] ?? ''),
                (string) ($app['checkout_cancel_url'] ?? '')
            );
            $error = AppCheckoutUrlConfig::validate(
                $config['checkout_success_url'],
                $config['checkout_cancel_url']
            );
            if ($error !== null) {
                throw new RuntimeException($error);
            }

            return [
                (string) $config['checkout_success_url'],
                (string) $config['checkout_cancel_url'],
            ];
        }

        $appUrl = rtrim((string) Env::get('APP_URL', ''), '/');
        if ($appUrl === '') {
            throw new RuntimeException('APP_URL is required');
        }

        return [
            (string) ($payload['successUrl'] ?? ($appUrl . '/checkout/success?session_id={CHECKOUT_SESSION_ID}')),
            (string) ($payload['cancelUrl'] ?? ($appUrl . '/checkout/cancel')),
        ];
    }

    private function normalizePurchaseKind(string $purchaseKind): string
    {
        $purchaseKind = trim($purchaseKind);
        if ($purchaseKind === '') {
            return 'license';
        }
        if (!in_array($purchaseKind, ['license', 'tenant_plan'], true)) {
            throw new RuntimeException('Invalid purchaseKind');
        }

        return $purchaseKind;
    }

    /** @param array<string,mixed> $tariff */
    private function resolveTargetPlanKey(string $purchaseKind, array $tariff): ?string
    {
        $targetPlanKey = trim((string) ($tariff['target_plan_key'] ?? ''));
        if ($purchaseKind !== 'tenant_plan') {
            return null;
        }
        if ($targetPlanKey === '') {
            throw new RuntimeException('tenant_plan tariff missing targetPlanKey');
        }

        return TenantPlanCatalog::definition($targetPlanKey)['key'];
    }

    /** @return array<string,mixed> */
    public function getSessionStatus(string $sessionId, ?string $appSlug = null): array
    {
        $sessionId = trim($sessionId);
        $appSlug = trim((string) $appSlug);

        if ($sessionId === '') {
            throw new RuntimeException('Missing session_id');
        }

        if (!preg_match('/^cs_(test_|live_)?[A-Za-z0-9]+$/', $sessionId)) {
            throw new RuntimeException('Invalid session_id');
        }

        $app = null;
        if ($appSlug !== '') {
            $app = (new AppRepository())->findBySlug($appSlug);
            if ($app === null || (int) ($app['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('Invalid appSlug');
            }
        }

        $order = (new TenantCheckoutOrderRepository())->findByStripeSessionId($sessionId);
        if ($order !== null && $app !== null && (int) $order['app_id'] !== (int) $app['id']) {
            return ['status' => 'not_found', 'sessionId' => $sessionId];
        }
        $secret = $this->resolveStripeSecret(($order['purchase_kind'] ?? '') === 'tenant_plan' ? null : $app);
        if ($secret === '') {
            throw new RuntimeException('Stripe is not configured');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required for Stripe API calls');
        }

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId));
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secret,
            ],
        ]);

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $curlErr !== '') {
            throw new RuntimeException('Stripe request failed: ' . $curlErr);
        }

        $decoded = Json::decodeObject($resp);
        if ($httpCode === 404) {
            return [
                'status' => 'not_found',
                'sessionId' => $sessionId,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = (string) ($decoded['error']['message'] ?? ('Stripe error HTTP ' . $httpCode));
            throw new RuntimeException($msg);
        }

        $metadata = is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [];
        if ($appSlug !== '' && (string) ($metadata['app_slug'] ?? '') !== $appSlug) {
            return [
                'status' => 'not_found',
                'sessionId' => $sessionId,
            ];
        }

        $stripeStatus = (string) ($decoded['status'] ?? '');
        $paymentStatus = (string) ($decoded['payment_status'] ?? '');

        $status = 'pending';
        if ($stripeStatus === 'complete' && in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            $status = 'completed';
        } elseif ($stripeStatus === 'expired') {
            $status = 'canceled';
        }

        $response = [
            'status' => $status,
            'sessionId' => $sessionId,
            'stripeStatus' => $stripeStatus,
            'paymentStatus' => $paymentStatus,
        ];

        if ((string) ($metadata['purchase_kind'] ?? 'license') === 'tenant_plan') {
            $order = (new TenantCheckoutOrderRepository())->findByStripeSessionId($sessionId);
            if ($order !== null) {
                if ($app !== null && (int) ($order['app_id'] ?? 0) !== (int) $app['id']) {
                    return [
                        'status' => 'not_found',
                        'sessionId' => $sessionId,
                    ];
                }

                $orderStatus = (string) ($order['status'] ?? 'pending');
                $response['onboardingStatus'] = $orderStatus;
                if ($orderStatus === 'completed') {
                    $response['status'] = 'completed';
                } elseif ($orderStatus === 'failed') {
                    $response['status'] = 'error';
                    if (!empty($order['error_message'])) {
                        $response['message'] = 'Unable to complete onboarding. Contact support.';
                    }
                } elseif ($status === 'completed') {
                    $response['status'] = 'pending';
                }
            }
        }

        return $response;
    }

    /** @param array<string,mixed>|null $app */
    private function resolveStripeSecret(?array $app): string
    {
        if (is_array($app) && !empty($app['tenant_id'])) {
            $tenantConfig = (new TenantStripeConnectionRepository())->findByTenantId((int) $app['tenant_id']);
            if ($tenantConfig !== null && (bool) ($tenantConfig['is_enabled'] ?? false)) {
                $secret = trim((string) ($tenantConfig['secret_key'] ?? ''));
                if ($secret !== '') {
                    return $secret;
                }
            }
        }

        $settings = new SettingsRepository();
        $dbKeys = $settings->getMultiple([
            'stripe_secret_key',
        ]);

        return trim((string) ($dbKeys['stripe_secret_key'] ?? Env::get('STRIPE_SECRET_KEY', '')));
    }

    /** @return string[] */
    private function getAllowedPriceIds(): array
    {
        try {
            $pdo = \App\Database::pdo();
            $stmt = $pdo->query('SELECT stripe_price_id FROM product_tariffs WHERE is_active = 1');
            $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            if ($ids !== []) {
                return $ids;
            }
        } catch (\Throwable) {
            // Table may not exist yet
        }

        return array_values(array_filter([
            Env::get('STRIPE_PRICE_PRO_MONTHLY'),
            Env::get('STRIPE_PRICE_PRO_ANNUAL'),
            Env::get('STRIPE_PRICE_PRO_LIFETIME'),
            Env::get('STRIPE_PRICE_ULTIMATE_LIFETIME'),
            Env::get('STRIPE_PRICE_PROJECT'),
            Env::get('STRIPE_PRICE_ANNUAL'),
            Env::get('STRIPE_PRICE_WHITELABEL'),
        ]));
    }

    /** @return string[] */
    private function getAllowedLicenseTypes(): array
    {
        try {
            $pdo = \App\Database::pdo();
            $stmt = $pdo->query('SELECT DISTINCT license_type FROM product_tariffs WHERE is_active = 1');
            $types = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            if ($types !== []) {
                return array_values(array_unique(array_map('strval', $types)));
            }
        } catch (\Throwable) {
            // Tables may not exist yet on older deployments.
        }

        $legacyByPrice = [
            'monthly' => Env::get('STRIPE_PRICE_PRO_MONTHLY'),
            'annual' => Env::get('STRIPE_PRICE_PRO_ANNUAL') ?: Env::get('STRIPE_PRICE_ANNUAL'),
            'lifetime' => Env::get('STRIPE_PRICE_PRO_LIFETIME') ?: Env::get('STRIPE_PRICE_ULTIMATE_LIFETIME'),
            'project' => Env::get('STRIPE_PRICE_PROJECT'),
            'whitelabel' => Env::get('STRIPE_PRICE_WHITELABEL'),
        ];

        $types = [];
        foreach ($legacyByPrice as $type => $priceId) {
            if (is_string($priceId) && trim($priceId) !== '') {
                $types[] = $type;
            }
        }

        return $types;
    }
}
