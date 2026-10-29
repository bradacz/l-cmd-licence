<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TenantStripeConnectionRepository;

final class CustomerStripeService
{
    private TenantStripeConnectionRepository $connections;

    public function __construct()
    {
        $this->connections = new TenantStripeConnectionRepository();
    }

    /** @return array<string,mixed> */
    public function getConfig(int $tenantId): array
    {
        $config = $this->connections->findByTenantId($tenantId) ?? [];

        return [
            'is_enabled' => (bool) ($config['is_enabled'] ?? false),
            'publishable_key' => $this->mask((string) ($config['publishable_key'] ?? '')),
            'secret_key' => $this->mask((string) ($config['secret_key'] ?? '')),
            'webhook_secret' => $this->mask((string) ($config['webhook_secret'] ?? '')),
        ];
    }

    public function update(int $tenantId, array $payload): void
    {
        $current = $this->connections->findByTenantId($tenantId) ?? [];
        $fields = [];
        if (array_key_exists('is_enabled', $payload)) {
            $fields['is_enabled'] = (bool) $payload['is_enabled'];
        }
        foreach (['publishable_key', 'secret_key', 'webhook_secret'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = trim((string) $payload[$field]);
            // Masked values returned to the UI are placeholders, not new keys.
            if ($value !== '' && $value === $this->mask((string) ($current[$field] ?? ''))) {
                continue;
            }
            $fields[$field] = $value;
        }
        $this->connections->upsert($tenantId, $fields);
    }

    /** @return array<string,mixed> */
    public function testConnection(int $tenantId): array
    {
        $config = $this->connections->findByTenantId($tenantId);
        if ($config === null || trim((string) ($config['secret_key'] ?? '')) === '') {
            return ['success' => false, 'error' => 'Stripe secret key not configured'];
        }

        $ch = curl_init('https://api.stripe.com/v1/account');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['secret_key']],
        ]);

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $httpCode !== 200) {
            return ['success' => false, 'error' => 'Stripe API error', 'http_code' => $httpCode];
        }

        $data = json_decode((string) $resp, true);

        return [
            'success' => true,
            'account_id' => $data['id'] ?? null,
            'account_name' => $data['settings']['dashboard']['display_name'] ?? $data['business_profile']['name'] ?? null,
        ];
    }

    private function mask(string $value): string
    {
        return $value !== '' ? substr($value, 0, 8) . '***' . substr($value, -4) : '';
    }
}
