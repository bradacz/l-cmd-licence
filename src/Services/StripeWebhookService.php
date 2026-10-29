<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppContextRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TenantStripeConnectionRepository;
use App\Repositories\WebhookLogRepository;
use App\Support\Env;
use App\Support\Json;
use RuntimeException;
use DomainException;

final class StripeWebhookService
{
    public function __construct(
        private readonly WebhookLogRepository $logs = new WebhookLogRepository()
    ) {
    }

    /** @return array<string,mixed> */
    public function handle(string $payload, ?string $signature): array
    {
        $event = Json::decodeObject($payload);

        try {
            $tenantScope = $this->assertValidSignature($payload, $signature);
            $eventType = (string) ($event['type'] ?? 'unknown');
            $object = $event['data']['object'] ?? [];
            $eventId = (string) ($event['id'] ?? '');
            if (!preg_match('/^evt_[a-zA-Z0-9_]{1,240}$/D', $eventId) || !is_array($object)) {
                throw new DomainException('Invalid Stripe event.');
            }
            $this->assertTenantScope($eventType, $object, $tenantScope);
            (new \App\Repositories\WebhookReceiptRepository())->runOnce(
                $tenantScope === null ? 'global' : 'tenant:' . $tenantScope,
                $eventId,
                function () use ($eventType, $object): void {
                    $this->dispatch($eventType, $object);
                    $this->logs->log($eventType, $object, null, 'success');
                }
            );

            return ['received' => true];
        } catch (\Throwable $e) {
            $eventType = (string) ($event['type'] ?? 'unknown');
            $this->logs->log($eventType, ['event_id' => $event['id'] ?? null], null, 'failed', get_class($e));
            throw $e;
        }
    }

    private function assertValidSignature(string $payload, ?string $signature): ?int
    {
        $secrets = $this->resolveWebhookSecrets();
        if ($secrets === []) {
            throw new RuntimeException('Stripe webhook secret missing');
        }

        if (!$signature) {
            throw new DomainException('Missing Stripe-Signature header');
        }

        $parts = [];
        $v1Signatures = [];
        foreach (explode(',', $signature) as $entry) {
            [$k, $v] = array_pad(explode('=', trim($entry), 2), 2, null);
            if ($k && $v) {
                if ($k === 'v1') {
                    $v1Signatures[] = $v;
                } else {
                    $parts[$k] = $v;
                }
            }
        }

        $timestamp = $parts['t'] ?? null;
        if (!$timestamp || $v1Signatures === []) {
            throw new DomainException('Invalid Stripe signature format');
        }
        if (!ctype_digit($timestamp)) {
            throw new DomainException('Invalid Stripe signature timestamp');
        }
        // Reject stale signatures outside 5-minute window.
        if (abs(time() - (int) $timestamp) > 300) {
            throw new DomainException('Stripe signature timestamp out of tolerance');
        }

        $signedPayload = $timestamp . '.' . $payload;
        $matches = [];
        foreach ($secrets as $entry) {
            $expected = hash_hmac('sha256', $signedPayload, $entry['secret']);
            foreach ($v1Signatures as $sig) {
                if (hash_equals($expected, $sig)) {
                    $matches[$entry['tenant_id'] ?? 'global'] = $entry['tenant_id'];
                }
            }
        }
        if (count($matches) === 1) {
            return array_values($matches)[0];
        }
        throw new DomainException('Invalid or ambiguous Stripe signature');
    }

    private function dispatch(string $type, array $object): void
    {
        match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->handleCheckoutCompleted($object),
            'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($object),
            'charge.refunded' => $this->handleChargeRefunded($object),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($object),
            default => null,
        };
    }

    private function handleCheckoutCompleted(array $object): void
    {
        if (!in_array($object['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) {
            return;
        }
        $metadata = $object['metadata'] ?? [];
        if ((string) ($metadata['purchase_kind'] ?? 'license') === 'tenant_plan') {
            (new TenantPlanCheckoutService())->handleCheckoutCompleted($object);
            return;
        }

        $licenseType = (string) ($metadata['license_type'] ?? 'lifetime');
        $productSlug = trim((string) ($metadata['product_slug'] ?? ''));
        $productName = trim((string) ($metadata['product_name'] ?? ''));
        $appSlug = trim((string) ($metadata['app_slug'] ?? ''));
        $customerEmail = (string) ($object['customer_details']['email'] ?? '');
        $customerName = (string) ($object['customer_details']['name'] ?? '');
        $currency = strtoupper((string) ($object['currency'] ?? 'CZK'));
        $amountTotal = isset($object['amount_total']) ? ((float) $object['amount_total']) / 100 : null;

        if ($customerEmail === '') {
            return;
        }

        $generator = new LicenseKeyGenerator();
        $key = $generator->generate($licenseType, $productName, $productSlug);
        $pdo = \App\Database::pdo();
        $appId = $this->checkoutAppId($object);
        $tenantId = $appId > 0
            ? (new TenantContextService())->tenantIdForAppId($appId)
            : (new TenantContextService())->defaultTenantId();
        $orderId = (string) ($object['id'] ?? '');
        if ($orderId === '') {
            throw new DomainException('Missing checkout session ID.');
        }
        $expiresAt = match ($licenseType) {
            'monthly' => date('Y-m-d H:i:s', strtotime('+1 month')),
            'annual' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'lifetime' => null,
            default => null,
        };

        $maxActivations = (new PolicyResolver())->resolveMaxActivations($appId, 3);

        $licenseId = \App\Database::transaction(function () use ($pdo, $tenantId, $orderId, $appId, $key, $licenseType, $productSlug, $productName, $appSlug, $customerEmail, $customerName, $object, $amountTotal, $currency, $maxActivations, $expiresAt): ?int {
            $lock = $pdo->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
            $lock->execute([$tenantId]);
            $existing = $pdo->prepare('SELECT id FROM licenses WHERE tenant_id = ? AND order_id = ? LIMIT 1 FOR UPDATE');
            $existing->execute([$tenantId, $orderId]);
            if ($existing->fetchColumn() !== false) {
                return null;
            }
            (new TenantBillingService())->assertCanIssueActiveLicense($tenantId);
            $stmt = $pdo->prepare('INSERT INTO licenses
            (tenant_id, app_id, license_key, license_type, product_slug, product_name, customer_email, customer_name, stripe_customer_id, stripe_subscription_id, stripe_payment_intent_id, order_id, price, currency, status, max_activations, expires_at)
            VALUES
            (:tenant_id, :app_id, :license_key, :license_type, :product_slug, :product_name, :customer_email, :customer_name, :stripe_customer_id, :stripe_subscription_id, :stripe_payment_intent_id, :order_id, :price, :currency, :status, :max_activations, :expires_at)');

            $stmt->execute([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'license_key' => $key,
            'license_type' => $licenseType,
            'product_slug' => $productSlug !== '' ? $productSlug : null,
            'product_name' => $productName !== '' ? $productName : null,
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            'stripe_customer_id' => $object['customer'] ?? null,
            'stripe_subscription_id' => $object['subscription'] ?? null,
            'stripe_payment_intent_id' => $object['payment_intent'] ?? null,
            'order_id' => $object['id'] ?? null,
            'price' => $amountTotal,
            'currency' => $currency,
            'status' => 'active',
            'max_activations' => $maxActivations,
            'expires_at' => $expiresAt,
            ]);
            $licenseId = (int) $pdo->lastInsertId();
            $this->auditLog('license.created', $licenseId, [
                'app_slug' => $appSlug,
                'product_slug' => $productSlug,
                'product_name' => $productName,
                'license_type' => $licenseType,
                'customer_email' => $customerEmail,
            ]);
            return $licenseId;
        });
        if ($licenseId === null) {
            return;
        }
        try {
            (new TenantOverageBillingService())->syncForTenant($tenantId);
        } catch (\Throwable $e) {
            error_log('Overage sync failed after checkout completion: ' . $e->getMessage());
        }

        try {
            $mailService = new MailService();
            $mailService->sendLicenseCreated(
                $customerEmail,
                $key,
                $licenseType,
                $productName !== '' ? $productName : null,
                $customerName !== '' ? $customerName : null,
                $amountTotal,
                $currency,
                $expiresAt,
                $appId
            );
        } catch (\Throwable $mailErr) {
            error_log("Failed to send license email: " . $mailErr->getMessage());
        }
    }

    /** @return array<int,array{tenant_id:?int,secret:string}> */
    private function resolveWebhookSecrets(): array
    {
        $settings = new SettingsRepository();
        $globalSecret = trim((string) ($settings->get('stripe_webhook_secret') ?? Env::get('STRIPE_WEBHOOK_SECRET', '')));
        $tenantSecrets = (new TenantStripeConnectionRepository())->enabledWebhookSecrets();
        if ($globalSecret !== '') {
            $tenantSecrets[] = ['tenant_id' => null, 'secret' => $globalSecret];
        }

        return $tenantSecrets;
    }

    private function checkoutAppId(array $object): int
    {
        $metadata = $object['metadata'] ?? [];
        $slug = trim((string) ($metadata['app_slug'] ?? ''));
        if ($slug !== '') {
            $app = (new \App\Repositories\AppRepository())->findBySlug($slug);
            if ($app === null || (int) $app['is_active'] !== 1) {
                throw new DomainException('Invalid checkout app context.');
            }
            return (int) $app['id'];
        }
        $context = new AppContextRepository();
        $product = (string) ($metadata['product_slug'] ?? '');
        $id = $context->resolveAppIdForProductSlug($product);
        if ($id <= 0) {
            throw new DomainException('Unable to resolve checkout app context.');
        }
        return $id;
    }

    private function assertTenantScope(string $type, array $object, ?int $scope): void
    {
        if ($scope === null) {
            return;
        }
        $context = new TenantContextService();
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $ids = [$context->tenantIdForAppId($this->checkoutAppId($object))];
            if (($object['metadata']['purchase_kind'] ?? '') === 'tenant_plan') {
                // Platform subscriptions cannot be authorized with a customer-owned secret.
                throw new DomainException('Tenant secret cannot authorize platform billing.');
            }
        } else {
            $lookup = match ($type) {
                'invoice.payment_succeeded' => ['stripe_subscription_id', $this->subscriptionId($object)],
                'customer.subscription.deleted' => ['stripe_subscription_id', (string) ($object['id'] ?? '')],
                'charge.refunded' => ['stripe_payment_intent_id', (string) ($object['payment_intent'] ?? '')],
                default => null,
            };
            if ($lookup === null || $lookup[1] === '') {
                return;
            }
            [$column, $value] = $lookup;
            $pdo = \App\Database::pdo();
            $orders = $pdo->prepare("SELECT id FROM tenant_checkout_orders WHERE {$column} = ? AND purchase_kind = 'tenant_plan' LIMIT 1");
            $orders->execute([$value]);
            if ($orders->fetchColumn() !== false) {
                throw new DomainException('Tenant secret cannot authorize platform billing.');
            }
            $stmt = $pdo->prepare("SELECT id FROM licenses WHERE {$column} = ?");
            $stmt->execute([$value]);
            $ids = array_map(static fn ($id) => $context->tenantIdForLicenseId((int) $id), $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        foreach ($ids as $tenantId) {
            if ($tenantId !== $scope) {
                throw new DomainException('Stripe event belongs to another tenant.');
            }
        }
    }

    private function subscriptionId(array $object): string
    {
        return (string) ($object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? '');
    }

    private function handleInvoicePaymentSucceeded(array $object): void
    {
        $subscriptionId = $this->subscriptionId($object);
        if ($subscriptionId === '') {
            return;
        }

        $tenantPlanOrder = (new \App\Repositories\TenantCheckoutOrderRepository())->findByStripeSubscriptionId($subscriptionId);
        if ($tenantPlanOrder !== null && (string) ($tenantPlanOrder['purchase_kind'] ?? '') === 'tenant_plan') {
            (new TenantPlanCheckoutService())->handleInvoicePaymentSucceeded($object);
            return;
        }

        $pdo = \App\Database::pdo();
        $find = $pdo->prepare('SELECT id, license_type FROM licenses WHERE stripe_subscription_id = :sub LIMIT 1');
        $find->execute(['sub' => $subscriptionId]);
        $license = $find->fetch();

        if (!$license) {
            return;
        }

        // Use the paid subscription period, not webhook arrival time or a guessed interval.
        $periodEnd = 0;
        foreach ($object['lines']['data'] ?? [] as $line) {
            $lineSubscription = $line['subscription'] ?? $line['parent']['subscription_item_details']['subscription'] ?? null;
            if ($lineSubscription === $subscriptionId) {
                $periodEnd = max($periodEnd, (int) ($line['period']['end'] ?? 0));
            }
        }
        if ($periodEnd <= 0 || !in_array($license['license_type'], ['monthly', 'annual'], true)) {
            return;
        }
        $stmt = $pdo->prepare("UPDATE licenses SET expires_at = :period_end, status='active'
            WHERE id = :id AND status IN ('active', 'expired') AND (expires_at IS NULL OR expires_at < :new_expiry)");
        $expiry = gmdate('Y-m-d H:i:s', $periodEnd);
        $stmt->execute(['id' => $license['id'], 'period_end' => $expiry, 'new_expiry' => $expiry]);
        if ($stmt->rowCount() === 0) {
            return;
        }

        $this->auditLog('license.renewed', (int) $license['id'], [
            'license_type' => $license['license_type'],
            'period_end' => $expiry,
        ]);

        try {
            $mailService = new MailService();
            $mailService->sendRenewalSuccess((int) $license['id']);
        } catch (\Throwable $e) {
            error_log("Failed to send renewal email: " . $e->getMessage());
        }
    }

    private function handleChargeRefunded(array $object): void
    {
        $paymentIntentId = (string) ($object['payment_intent'] ?? '');
        if ($paymentIntentId === '') {
            return;
        }

        $tenantPlanOrder = (new \App\Repositories\TenantCheckoutOrderRepository())->findByStripePaymentIntentId($paymentIntentId);
        if ($tenantPlanOrder !== null && (string) ($tenantPlanOrder['purchase_kind'] ?? '') === 'tenant_plan') {
            if (!empty($tenantPlanOrder['tenant_id'])) {
                $tenant = (new \App\Repositories\TenantRepository())->findById((int) $tenantPlanOrder['tenant_id']);
                (new TenantBillingService())->updateTenantBilling((int) $tenantPlanOrder['tenant_id'], [
                    'plan_key' => 'free',
                    'billing_status' => 'refunded',
                    'currency' => \App\Support\TenantPlanCatalog::normalizeCurrency((string) ($object['currency'] ?? ($tenant['default_currency'] ?? 'EUR'))),
                    'stripe_customer_id' => (string) ($tenantPlanOrder['stripe_customer_id'] ?? ''),
                    'stripe_subscription_id' => (string) ($tenantPlanOrder['stripe_subscription_id'] ?? ''),
                    'stripe_price_id' => (string) ($tenantPlanOrder['stripe_price_id'] ?? ''),
                ]);
            }
            (new \App\Repositories\AuditLogRepository())->log(
                null,
                null,
                'tenant.billing.refunded',
                'tenant_checkout_order',
                (int) $tenantPlanOrder['id'],
                [],
                [
                    'tenant_id' => !empty($tenantPlanOrder['tenant_id']) ? (int) $tenantPlanOrder['tenant_id'] : null,
                    'payment_intent' => $paymentIntentId,
                ],
                '0.0.0.0',
                'stripe-webhook'
            );
            return;
        }

        $pdo = \App\Database::pdo();
        $find = $pdo->prepare('SELECT id FROM licenses WHERE stripe_payment_intent_id = :pi LIMIT 1');
        $find->execute(['pi' => $paymentIntentId]);
        $license = $find->fetch();

        $stmt = $pdo->prepare("UPDATE licenses SET status='refunded', revoke_reason='Payment refunded' WHERE stripe_payment_intent_id = :pi");
        $stmt->execute(['pi' => $paymentIntentId]);

        if ($license) {
            try {
                $tenantId = (new TenantContextService())->tenantIdForLicenseId((int) $license['id']);
                (new TenantOverageBillingService())->syncForTenant($tenantId);
            } catch (\Throwable $e) {
                error_log('Overage sync failed after refund: ' . $e->getMessage());
            }
            $this->auditLog('license.refunded', (int) $license['id'], [
                'payment_intent' => $paymentIntentId,
            ]);
        }
    }

    private function handleSubscriptionDeleted(array $object): void
    {
        $subscriptionId = (string) ($object['id'] ?? '');
        if ($subscriptionId === '') {
            return;
        }

        $tenantPlanOrder = (new \App\Repositories\TenantCheckoutOrderRepository())->findByStripeSubscriptionId($subscriptionId);
        if ($tenantPlanOrder !== null && (string) ($tenantPlanOrder['purchase_kind'] ?? '') === 'tenant_plan') {
            (new TenantPlanCheckoutService())->handleSubscriptionDeleted($object);
            return;
        }

        $pdo = \App\Database::pdo();
        $find = $pdo->prepare('SELECT id FROM licenses WHERE stripe_subscription_id = :sub LIMIT 1');
        $find->execute(['sub' => $subscriptionId]);
        $license = $find->fetch();

        $stmt = $pdo->prepare("UPDATE licenses SET status='expired' WHERE stripe_subscription_id = :sub");
        $stmt->execute(['sub' => $subscriptionId]);

        if ($license) {
            try {
                $tenantId = (new TenantContextService())->tenantIdForLicenseId((int) $license['id']);
                (new TenantOverageBillingService())->syncForTenant($tenantId);
            } catch (\Throwable $e) {
                error_log('Overage sync failed after subscription delete: ' . $e->getMessage());
            }
            $this->auditLog('license.subscription_deleted', (int) $license['id'], [
                'subscription_id' => $subscriptionId,
            ]);
        }
    }

    private function auditLog(string $action, int $licenseId, array $details): void
    {
        $audit = new \App\Repositories\AuditLogRepository();
        $audit->log(null, $licenseId, $action, 'license', $licenseId, [], $details, '0.0.0.0', 'stripe-webhook');
    }
}
