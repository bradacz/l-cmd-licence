<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use App\Repositories\TenantSettingsRepository;
use App\Support\Env;
use App\Support\Json;

final class TenantOverageBillingService
{
    public function __construct(
        private readonly TenantBillingService $billing = new TenantBillingService(),
        private readonly TenantSettingsRepository $settings = new TenantSettingsRepository(),
        private readonly SettingsRepository $globalSettings = new SettingsRepository()
    ) {
    }

    /** @return array<string,mixed> */
    public function syncForTenant(int $tenantId): array
    {
        $summary = $this->billing->summaryForTenant($tenantId);
        $plan = $summary['plan'];
        $billing = $summary['billing'];
        $usage = $summary['usage'];
        $billingCurrency = \App\Support\TenantPlanCatalog::normalizeCurrency((string) ($billing['currency'] ?? 'EUR'));
        $customerId = trim((string) ($billing['stripe_customer_id'] ?? ''));
        $status = (string) ($billing['status'] ?? '');
        $desiredAmountMinor = (int) round(((float) ($usage['projected_overage_amount'] ?? 0.0)) * 100);
        $desiredQuantity = (int) ($usage['overage_active_licenses'] ?? 0);
        $existingItemId = trim((string) ($this->settings->allForTenant($tenantId)['billing_overage_invoice_item_id'] ?? ''));

        if ($customerId === '' || !in_array($status, ['active', 'trialing', 'past_due'], true) || (float) ($usage['extra_active_license_price'] ?? 0.0) <= 0.0) {
            if ($existingItemId !== '') {
                $delete = $this->deleteInvoiceItem($existingItemId);
                if (($delete['success'] ?? false) === false) {
                    $this->markSyncFailed($tenantId, (string) ($delete['error'] ?? 'Unable to delete stale overage invoice item'));
                    return $delete;
                }
            }
            $this->clearStoredOverageSync($tenantId);
            $this->markSyncState($tenantId, 'skipped', null);
            return [
                'success' => true,
                'mode' => 'skipped',
                'reason' => 'billing_not_chargeable',
            ];
        }

        if ($desiredAmountMinor <= 0 || $desiredQuantity <= 0) {
            if ($existingItemId !== '') {
                $delete = $this->deleteInvoiceItem($existingItemId);
                if (($delete['success'] ?? false) === false) {
                    $this->markSyncFailed($tenantId, (string) ($delete['error'] ?? 'Unable to delete stale overage invoice item'));
                    return $delete;
                }
            }
            $this->clearStoredOverageSync($tenantId);
            $this->markSyncState($tenantId, 'synced', null);

            return [
                'success' => true,
                'mode' => 'synced',
                'quantity' => 0,
                'amount_minor' => 0,
                'invoice_item_id' => null,
            ];
        }

        $description = sprintf(
            'SimplyKeys overage: %d extra active licenses on %s plan',
            $desiredQuantity,
            (string) ($plan['label'] ?? (string) ($plan['key'] ?? 'tenant'))
        );

        if ($existingItemId !== '') {
            $result = $this->requestStripe('POST', 'https://api.stripe.com/v1/invoiceitems/' . rawurlencode($existingItemId), [
                'amount' => (string) $desiredAmountMinor,
                'currency' => strtolower($billingCurrency),
                'description' => $description,
                'metadata[tenant_id]' => (string) $tenantId,
                'metadata[type]' => 'simplykeys_overage',
                'metadata[quantity]' => (string) $desiredQuantity,
            ], false);

            if (($result['success'] ?? false) === false) {
                $this->markSyncFailed($tenantId, (string) ($result['error'] ?? 'Unable to update Stripe overage invoice item'));
                $existingItemId = '';
            }
        }

        if ($existingItemId === '') {
            $result = $this->requestStripe('POST', 'https://api.stripe.com/v1/invoiceitems', [
                'customer' => $customerId,
                'amount' => (string) $desiredAmountMinor,
                'currency' => strtolower($billingCurrency),
                'description' => $description,
                'metadata[tenant_id]' => (string) $tenantId,
                'metadata[type]' => 'simplykeys_overage',
                'metadata[quantity]' => (string) $desiredQuantity,
            ]);

            if (($result['success'] ?? false) === false) {
                $this->markSyncFailed($tenantId, (string) ($result['error'] ?? 'Unable to create Stripe overage invoice item'));
                return $result;
            }

            $existingItemId = (string) ($result['invoice_item_id'] ?? '');
        }

        $this->settings->upsert($tenantId, 'billing_overage_invoice_item_id', $existingItemId !== '' ? $existingItemId : null);
        $this->settings->upsert($tenantId, 'billing_overage_synced_quantity', (string) $desiredQuantity);
        $this->settings->upsert($tenantId, 'billing_overage_synced_amount_minor', (string) $desiredAmountMinor);
        $this->settings->upsert($tenantId, 'billing_overage_synced_at', gmdate('Y-m-d H:i:s'));
        $this->markSyncState($tenantId, 'synced', null);

        return [
            'success' => true,
            'mode' => 'synced',
            'quantity' => $desiredQuantity,
            'amount_minor' => $desiredAmountMinor,
            'invoice_item_id' => $existingItemId !== '' ? $existingItemId : null,
        ];
    }

    private function clearStoredOverageSync(int $tenantId): void
    {
        foreach ([
            'billing_overage_invoice_item_id',
            'billing_overage_synced_quantity',
            'billing_overage_synced_amount_minor',
            'billing_overage_synced_at',
        ] as $key) {
            $this->settings->upsert($tenantId, $key, null);
        }
    }

    /** @return array<string,mixed> */
    private function deleteInvoiceItem(string $invoiceItemId): array
    {
        return $this->requestStripe('DELETE', 'https://api.stripe.com/v1/invoiceitems/' . rawurlencode($invoiceItemId), [], false);
    }

    private function markSyncState(int $tenantId, string $status, ?string $error): void
    {
        $this->settings->upsert($tenantId, 'billing_overage_sync_status', $status);
        $this->settings->upsert($tenantId, 'billing_overage_sync_error', $error);
    }

    private function markSyncFailed(int $tenantId, string $error): void
    {
        $this->markSyncState($tenantId, 'failed', $error);
    }

    /** @param array<string,string> $form
      * @return array<string,mixed> */
    private function requestStripe(string $method, string $url, array $form, bool $expectId = true): array
    {
        $secret = trim((string) ($this->globalSettings->get('stripe_secret_key') ?? Env::get('STRIPE_SECRET_KEY', '')));
        if ($secret === '') {
            return ['success' => false, 'error' => 'Platform Stripe secret missing'];
        }
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'cURL extension is required'];
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secret,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ];
        if ($form !== []) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        curl_setopt_array($ch, $options);

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $curlErr !== '') {
            return ['success' => false, 'error' => 'Stripe request failed: ' . $curlErr];
        }

        $decoded = Json::decodeObject($resp);
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => (string) ($decoded['error']['message'] ?? ('Stripe error HTTP ' . $httpCode))];
        }

        return [
            'success' => true,
            'invoice_item_id' => $expectId ? (string) ($decoded['id'] ?? '') : (string) ($decoded['id'] ?? ''),
        ];
    }
}
