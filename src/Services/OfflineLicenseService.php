<?php

declare(strict_types=1);

namespace App\Services;

final class OfflineLicenseService
{
    /** @param array<string,mixed> $license
      * @return array<string,mixed> */
    public function issue(array $license, string $installationHash, int $tenantId): array
    {
        $settings = (new \App\Repositories\TenantSettingsRepository())->allForTenant($tenantId);
        $ttlDays = max(1, min(90, (int) ($settings['offline_license_ttl_days'] ?? 30)));
        $expiresAt = strtotime('+' . $ttlDays . ' days');
        if (!empty($license['expires_at'])) {
            $licenseExpiry = strtotime((string) $license['expires_at']);
            if ($licenseExpiry !== false) {
                $expiresAt = min($expiresAt, $licenseExpiry);
            }
        }

        $payload = [
            'type' => 'offline_license',
            'tenant_id' => $tenantId,
            'license_id' => (int) ($license['id'] ?? 0),
            'license_key' => (string) ($license['license_key'] ?? ''),
            'app_id' => isset($license['app_id']) ? (int) $license['app_id'] : null,
            'app_slug' => $license['app_slug'] ?? null,
            'product_slug' => $license['product_slug'] ?? null,
            'installation_hash' => $installationHash,
            'features' => array_values(array_filter((array) ($license['features'] ?? []), 'is_string')),
            'iat' => time(),
            'exp' => $expiresAt,
        ];
        $signer = new OfflineTokenSigner();
        $publicKey = $signer->publicKeyData($tenantId);

        return [
            'token' => $signer->issue($tenantId, $payload),
            'expires_at' => gmdate('c', $expiresAt),
            'ttl_seconds' => max(0, $expiresAt - time()),
            'algorithm' => 'RS256',
            'key_id' => $publicKey['key_id'],
        ];
    }
}
