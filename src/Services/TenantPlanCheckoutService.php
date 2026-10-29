<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\TenantCheckoutOrderRepository;
use App\Repositories\TenantMembershipRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TenantUserRepository;
use App\Support\TenantPlanCatalog;
use App\Support\Env;

final class TenantPlanCheckoutService
{
    public function handleCheckoutCompleted(array $object): void
    {
        if (!in_array($object['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) {
            return;
        }
        $sessionId = trim((string) ($object['id'] ?? ''));
        if ($sessionId === '') {
            return;
        }

        $pdo = Database::pdo();
        $orders = new TenantCheckoutOrderRepository();
        $pdo->beginTransaction();
        try {
            $order = $orders->findByStripeSessionIdForUpdate($sessionId);
            if ($order === null || (string) ($order['purchase_kind'] ?? '') !== 'tenant_plan') {
                $pdo->commit();
                return;
            }
            if ((string) ($order['status'] ?? '') === 'completed') {
                $pdo->commit();
                return;
            }

            $email = mb_strtolower(trim((string) ($object['customer_details']['email'] ?? $order['customer_email'] ?? '')));
            if ($email === '') {
                $orders->update((int) $order['id'], [
                    'status' => 'failed',
                    'error_message' => 'Missing customer email',
                ]);
                $this->audit('tenant.checkout.failed', (int) $order['id'], [
                    'reason' => 'missing_customer_email',
                    'stripe_session_id' => $sessionId,
                ]);
                $pdo->commit();
                return;
            }

            $tenant = $this->resolveTenant($email, (string) ($object['customer_details']['name'] ?? ''));
            $billingCurrency = TenantPlanCatalog::normalizeCurrency((string) ($object['currency'] ?? ($tenant['default_currency'] ?? 'EUR')));
            (new TenantBillingService())->updateTenantBilling((int) $tenant['id'], [
                'plan_key' => (string) ($order['target_plan_key'] ?? 'free'),
                'billing_status' => 'active',
                'currency' => $billingCurrency,
                'stripe_customer_id' => trim((string) ($object['customer'] ?? '')),
                'stripe_subscription_id' => trim((string) ($object['subscription'] ?? '')),
                'stripe_price_id' => (string) ($order['stripe_price_id'] ?? ''),
            ]);

            $access = (new CustomerAuthService())->issueOnboardingAccessKey(
                (int) $tenant['id'],
                $email,
                (string) Env::get('CUSTOMER_PORTAL_URL', 'https://portal.example.invalid'),
                (string) TenantPlanCatalog::definition((string) ($order['target_plan_key'] ?? 'free'))['label']
            );
            $orders->update((int) $order['id'], [
                'tenant_id' => (int) $tenant['id'],
                'stripe_customer_id' => trim((string) ($object['customer'] ?? '')) ?: null,
                'stripe_subscription_id' => trim((string) ($object['subscription'] ?? '')) ?: null,
                'stripe_payment_intent_id' => trim((string) ($object['payment_intent'] ?? '')) ?: null,
                'status' => 'completed',
                'access_key_id' => (int) ($access['access_key_id'] ?? 0) ?: null,
                'completed_at' => date('Y-m-d H:i:s'),
                'email_sent_at' => !empty($access['email_sent']) ? date('Y-m-d H:i:s') : null,
                'error_message' => null,
            ]);
            $this->audit('tenant.checkout.completed', (int) $order['id'], [
                'tenant_id' => (int) $tenant['id'],
                'target_plan_key' => (string) ($order['target_plan_key'] ?? 'free'),
                'stripe_session_id' => $sessionId,
                'email_sent' => !empty($access['email_sent']),
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $order = $orders->findByStripeSessionId($sessionId);
            if ($order !== null) {
                $orders->update((int) $order['id'], [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                $this->audit('tenant.checkout.failed', (int) $order['id'], [
                    'stripe_session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }

    public function handleInvoicePaymentSucceeded(array $object): void
    {
        $subscriptionId = trim((string) ($object['subscription'] ?? ''));
        if ($subscriptionId === '') {
            return;
        }

        $order = (new TenantCheckoutOrderRepository())->findByStripeSubscriptionId($subscriptionId);
        if ($order === null || (string) ($order['purchase_kind'] ?? '') !== 'tenant_plan' || empty($order['tenant_id'])) {
            return;
        }

        (new TenantBillingService())->updateTenantBilling((int) $order['tenant_id'], [
            'plan_key' => (string) ($order['target_plan_key'] ?? 'free'),
            'billing_status' => 'active',
            'currency' => TenantPlanCatalog::normalizeCurrency((string) ($object['currency'] ?? 'EUR')),
            'stripe_customer_id' => trim((string) ($object['customer'] ?? '')),
            'stripe_subscription_id' => $subscriptionId,
            'stripe_price_id' => (string) ($order['stripe_price_id'] ?? ''),
            'current_period_starts_at' => $this->timestampToDateTime($object['period_start'] ?? null),
            'current_period_ends_at' => $this->timestampToDateTime($object['period_end'] ?? null),
        ]);
        $this->audit('tenant.billing.updated', (int) $order['id'], [
            'tenant_id' => (int) $order['tenant_id'],
            'subscription_id' => $subscriptionId,
            'status' => 'active',
        ]);
    }

    public function handleSubscriptionDeleted(array $object): void
    {
        $subscriptionId = trim((string) ($object['id'] ?? ''));
        if ($subscriptionId === '') {
            return;
        }

        $order = (new TenantCheckoutOrderRepository())->findByStripeSubscriptionId($subscriptionId);
        if ($order === null || (string) ($order['purchase_kind'] ?? '') !== 'tenant_plan' || empty($order['tenant_id'])) {
            return;
        }

        (new TenantBillingService())->updateTenantBilling((int) $order['tenant_id'], [
            'plan_key' => 'free',
            'billing_status' => 'canceled',
            'currency' => $this->existingTenantCurrency((int) $order['tenant_id'], (string) ($object['currency'] ?? '')),
            'stripe_customer_id' => trim((string) ($object['customer'] ?? '')),
            'stripe_subscription_id' => $subscriptionId,
            'stripe_price_id' => (string) ($order['stripe_price_id'] ?? ''),
        ]);
        $this->audit('tenant.billing.canceled', (int) $order['id'], [
            'tenant_id' => (int) $order['tenant_id'],
            'subscription_id' => $subscriptionId,
        ]);
    }

    /** @return array<string,mixed> */
    public function reissueOnboardingAccessKeyForOrder(int $orderId): array
    {
        $order = (new TenantCheckoutOrderRepository())->findById($orderId);
        if ($order === null || (string) ($order['purchase_kind'] ?? '') !== 'tenant_plan') {
            throw new \RuntimeException('Tenant checkout order not found.');
        }
        if (empty($order['tenant_id'])) {
            throw new \RuntimeException('Tenant is not assigned to this checkout order.');
        }

        $email = mb_strtolower(trim((string) ($order['customer_email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('Missing customer email.');
        }

        $planLabel = (string) TenantPlanCatalog::definition((string) ($order['target_plan_key'] ?? 'free'))['label'];
        $access = (new CustomerAuthService())->issueOnboardingAccessKey(
            (int) $order['tenant_id'],
            $email,
            (string) Env::get('CUSTOMER_PORTAL_URL', 'https://portal.example.invalid'),
            $planLabel
        );

        (new TenantCheckoutOrderRepository())->update($orderId, [
            'access_key_id' => (int) ($access['access_key_id'] ?? 0) ?: null,
            'email_sent_at' => !empty($access['email_sent']) ? date('Y-m-d H:i:s') : null,
            'error_message' => null,
        ]);
        $this->audit('tenant.onboarding_email_resent', $orderId, [
            'tenant_id' => (int) $order['tenant_id'],
            'email' => $email,
            'email_sent' => !empty($access['email_sent']),
        ]);

        return $access;
    }

    private function resolveTenant(string $email, string $customerName): array
    {
        $users = new TenantUserRepository();
        $memberships = new TenantMembershipRepository();
        $user = $users->findByEmail($email);
        if ($user !== null) {
            foreach ($memberships->activeMembershipsForUser((int) $user['id']) as $membership) {
                if ((int) ($membership['is_demo'] ?? 0) !== 1) {
                    $tenant = (new TenantRepository())->findById((int) $membership['tenant_id']);
                    if ($tenant !== null) {
                        return $tenant;
                    }
                }
            }
        }

        $repo = new TenantRepository();
        $baseSlug = $this->slugify($customerName !== '' ? $customerName : (strstr($email, '@', true) ?: 'tenant'));
        $slug = $baseSlug;
        $suffix = 2;
        while ($repo->findBySlug($slug) !== null) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        $tenantId = $repo->create([
            'slug' => $slug,
            'name' => $customerName !== '' ? $customerName : $slug,
            'status' => 'active',
            'plan_key' => 'free',
            'default_currency' => 'EUR',
            'is_demo' => 0,
        ]);

        return $repo->findById($tenantId) ?? throw new \RuntimeException('Tenant creation failed.');
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }

        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'tenant';
        $value = trim($value, '-');

        return $value !== '' ? substr($value, 0, 60) : 'tenant';
    }

    private function timestampToDateTime(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        return date('Y-m-d H:i:s', (int) $value);
    }

    private function existingTenantCurrency(int $tenantId, string $preferredCurrency): string
    {
        if (trim($preferredCurrency) !== '') {
            return TenantPlanCatalog::normalizeCurrency($preferredCurrency);
        }

        $tenant = (new TenantRepository())->findById($tenantId);

        return TenantPlanCatalog::normalizeCurrency((string) ($tenant['default_currency'] ?? 'EUR'));
    }

    private function audit(string $action, int $orderId, array $details): void
    {
        (new AuditLogRepository())->log(
            null,
            null,
            $action,
            'tenant_checkout_order',
            $orderId,
            [],
            $details,
            '0.0.0.0',
            'stripe-webhook'
        );
    }
}
