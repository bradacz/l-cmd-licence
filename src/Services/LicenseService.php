<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Repositories\ActivationRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\VerificationRepository;
use DomainException;

final class LicenseService
{
    public function __construct(
        private readonly LicenseRepository $licenses = new LicenseRepository(),
        private readonly ActivationRepository $activations = new ActivationRepository(),
        private readonly VerificationRepository $verifications = new VerificationRepository(),
        private readonly LicenseKeyGenerator $keys = new LicenseKeyGenerator(),
        private readonly PolicyResolver $policies = new PolicyResolver()
    ) {
    }

    /** @param array<string,mixed> $systemInfo
      * @return array<string,mixed> */
    public function activate(string $licenseKey, string $domain, string $installationHash, array $systemInfo, string $ip, string $userAgent): array
    {
        if (!$this->keys->validate($licenseKey)) {
            throw new DomainException('Invalid license key format');
        }

        return Database::transaction(function () use ($licenseKey, $domain, $installationHash, $systemInfo, $ip, $userAgent): array {
            $license = $this->licenses->findByKey($licenseKey, true);
            if (!$license || $license['status'] !== 'active') {
                throw new DomainException('License not found or inactive');
            }

            if ($this->isLicenseExpired($license)) {
                throw new DomainException('License has expired');
            }
            if (!$this->isPlatformAllowed($license, (string) ($systemInfo['platform'] ?? ''))) {
                throw new DomainException('Platform not allowed');
            }

            $effectiveMaxActivations = $this->policies->resolveMaxActivations(
                isset($license['app_id']) ? (int) $license['app_id'] : null,
                (int) $license['max_activations']
            );
            if ((int) $license['current_activations'] >= $effectiveMaxActivations) {
                throw new DomainException('MAX_ACTIVATIONS_REACHED');
            }

            $existing = $this->activations->findActiveForLicense((int) $license['id'], $installationHash);
            if ($existing) {
                throw new DomainException('Installation already activated');
            }

            $activationId = $this->activations->create((int) $license['id'], [
                'domain' => $domain,
                'server_ip' => $ip,
                'installation_hash' => $installationHash,
                'php_version' => $systemInfo['php_version'] ?? null,
                'cms_version' => $systemInfo['cms_version'] ?? null,
                'database_type' => $systemInfo['database_type'] ?? null,
            ]);

            $this->licenses->incrementActivations((int) $license['id']);
            $this->licenses->setActivatedAtIfEmpty((int) $license['id']);

            $this->verifications->log((int) $license['id'], $activationId, 'valid', $ip, $userAgent);

            $tenantId = isset($license['tenant_id']) ? (int) $license['tenant_id'] : (new TenantContextService())->defaultTenantId();
            $billingSummary = (new TenantBillingService())->summaryForTenant($tenantId);
            $hardwareLockingEnabled = (bool) ($billingSummary['plan']['features']['hardware_locking'] ?? false);

            return [
                'status' => 'success',
                'license' => [
                    'type' => $license['license_type'],
                    'customer' => $license['customer_name'],
                    'expires_at' => $license['expires_at'],
                    'activations_remaining' => max(0, $effectiveMaxActivations - ((int) $license['current_activations'] + 1)),
                ],
                'activation_id' => $activationId,
                'capabilities' => [
                    'hardware_locking' => $hardwareLockingEnabled,
                    'offline_licenses' => (bool) ($billingSummary['plan']['features']['offline_licenses'] ?? false),
                ],
                'device_binding' => [
                    'enabled' => $hardwareLockingEnabled,
                    'installation_hash' => $installationHash,
                    'current_activations' => ((int) $license['current_activations']) + 1,
                    'max_activations' => $effectiveMaxActivations,
                ],
            ];
        });
    }

    /** @param array<string,mixed> $metadata
      * @return array<string,mixed> */
    public function verify(string $licenseKey, string $installationHash, string $ip, string $userAgent, array $metadata = []): array
    {
        return Database::transaction(fn (): array => $this->verifyLocked($licenseKey, $installationHash, $ip, $userAgent, $metadata));
    }

    private function verifyLocked(string $licenseKey, string $installationHash, string $ip, string $userAgent, array $metadata): array
    {
        // All activation changes serialize on the same parent license row.
        $license = $this->licenses->findByKey($licenseKey, true);
        if (!$license) {
            return ['status' => 'invalid', 'message' => 'Neplatný licenční klíč'];
        }

        $licenseId = (int) $license['id'];

        // Kontrola stavu licence
        if (in_array($license['status'], ['revoked', 'refunded'], true)) {
            $this->verifications->log($licenseId, null, 'revoked', $ip, $userAgent, null, 'Licence byla zrušena');
            return ['status' => 'revoked', 'message' => 'Licence byla zrušena'];
        }

        if ($license['status'] === 'expired') {
            $this->verifications->log($licenseId, null, 'expired', $ip, $userAgent, null, 'expired');
            return ['status' => 'expired', 'message' => 'Platnost licence vypršela'];
        }

        if ($license['status'] !== 'active') {
            return ['status' => 'invalid', 'message' => 'License is not active'];
        }

        // Rozlišení podle typu licence
        $licenseType = (string) $license['license_type'];
        if (!$this->isPlatformAllowed($license, (string) ($metadata['platform'] ?? ''))) {
            $this->verifications->log($licenseId, null, 'invalid', $ip, $userAgent, null, 'Platform not allowed');
            return ['status' => 'invalid', 'message' => 'Platform not allowed'];
        }

        if ($this->isLicenseExpired($license)) {
            $this->licenses->setExpired($licenseId);
            $this->verifications->log($licenseId, null, 'expired', $ip, $userAgent, null, 'expired');
            return ['status' => 'expired', 'message' => 'Platnost licence vypršela'];
        }

        // Ověření aktivace zařízení
        $activation = $this->activations->findActiveForLicense($licenseId, $installationHash);
        $effectiveMaxActivations = $this->policies->resolveMaxActivations(
            isset($license['app_id']) ? (int) $license['app_id'] : null,
            (int) $license['max_activations']
        );

        $createdActivation = false;
        if ($activation) {
            // Případ A: Zařízení je již známé
            $this->activations->touchPing((int) $activation['id']);
        } else {
            // Případ B: Nové zařízení - auto-aktivace
            if ((int) $license['current_activations'] >= $effectiveMaxActivations) {
                $this->verifications->log($licenseId, null, 'limit_exceeded', $ip, $userAgent, null, 'Bylo dosaženo maximálního počtu aktivací');
                return ['status' => 'limit_exceeded', 'message' => 'Bylo dosaženo maximálního počtu aktivací'];
            }

            $activationId = $this->activations->create($licenseId, [
                'domain' => $metadata['platform'] ?? 'unknown',
                'server_ip' => $ip,
                'installation_hash' => $installationHash,
                'php_version' => $metadata['app_version'] ?? null,
                'cms_version' => null,
                'database_type' => null,
            ]);
            $this->licenses->incrementActivations($licenseId);
            $this->licenses->setActivatedAtIfEmpty($licenseId);

            $activation = ['id' => $activationId];
            $createdActivation = true;
        }

        $this->licenses->touchVerified($licenseId);
        $this->verifications->log($licenseId, (int) $activation['id'], 'valid', $ip, $userAgent);

        // JWT odpověď
        $jwt = new JwtService();
        $now = time();
        $jwtExpiry = $now + $this->policies->resolveJwtTtlSeconds(
            isset($license['app_id']) ? (int) $license['app_id'] : null,
            7 * 86400
        );
        $effectiveExpiry = $this->effectiveExpiry($license);
        if ($effectiveExpiry !== null) {
            $jwtExpiry = min($jwtExpiry, $effectiveExpiry);
        }
        $tenantId = isset($license['tenant_id']) ? (int) $license['tenant_id'] : (new TenantContextService())->defaultTenantId();
        $billingSummary = (new TenantBillingService())->summaryForTenant($tenantId);
        $hardwareLockingEnabled = (bool) ($billingSummary['plan']['features']['hardware_locking'] ?? false);
        $offlineLicensesEnabled = (bool) ($billingSummary['plan']['features']['offline_licenses'] ?? false);

        $resolvedFeatures = $this->policies->resolveEnabledFeatures(
            isset($license['app_id']) ? (int) $license['app_id'] : null,
            $this->resolveFeatures($licenseType)
        );

        $payload = [
            'status' => 'valid',
            'license_id' => $licenseId,
            'installation_hash' => $installationHash,
            'app_id' => $license['app_id'] !== null ? (int) $license['app_id'] : null,
            'app_slug' => $license['app_slug'] ?? null,
            'license_type' => $licenseType,
            'product_slug' => $license['product_slug'] ?? null,
            'expires_at' => $license['expires_at'] ? gmdate('c', strtotime((string) $license['expires_at'])) : null,
            'features' => $resolvedFeatures,
            'iat' => $now,
            'exp' => $jwtExpiry,
        ];

        $response = [
            'status' => 'valid',
            'token' => $jwt->create($payload),
            'capabilities' => [
                'hardware_locking' => $hardwareLockingEnabled,
                'offline_licenses' => $offlineLicensesEnabled,
            ],
            'device_binding' => [
                'enabled' => $hardwareLockingEnabled,
                'installation_hash' => $installationHash,
                'current_activations' => ((int) $license['current_activations']) + ($createdActivation ? 1 : 0),
                'max_activations' => $effectiveMaxActivations,
            ],
        ];

        if ($offlineLicensesEnabled && !empty($metadata['request_offline_token'])) {
            $license['features'] = $resolvedFeatures;
            $response['offline'] = (new OfflineLicenseService())->issue($license, $installationHash, $tenantId);
        }

        return $response;
    }

    /** @return string[] */
    private function resolveFeatures(string $licenseType): array
    {
        return match ($licenseType) {
            'lifetime' => ['premium', 'sync', 'lifetime'],
            'annual' => ['premium', 'sync'],
            'monthly' => ['premium'],
            default => [],
        };
    }

    public function deactivate(string $licenseKey, string $installationHash, string $reason): void
    {
        Database::transaction(function () use ($licenseKey, $installationHash, $reason): void {
            $license = $this->licenses->findByKey($licenseKey, true);
            if (!$license) {
                return;
            }
            $activation = $this->activations->findActiveForLicense((int) $license['id'], $installationHash);
            if (!$activation) {
                return;
            }

            $this->activations->deactivate((int) $activation['id'], $reason);
            $this->licenses->decrementActivations((int) $license['id']);
        });
    }

    /** @param array<string,mixed> $license */
    private function isLicenseExpired(array $license): bool
    {
        $expiresAt = $this->effectiveExpiry($license);
        return $expiresAt !== null && $expiresAt <= time();
    }

    private function effectiveExpiry(array $license): ?int
    {
        if (empty($license['expires_at'])) {
            return null;
        }

        $expiresAt = strtotime((string) $license['expires_at']);
        if ($expiresAt === false) {
            throw new DomainException('Invalid license expiry');
        }

        $gracePeriodDays = $this->policies->resolveGracePeriodDays(
            isset($license['app_id']) ? (int) $license['app_id'] : null
        );
        if ($gracePeriodDays !== null) {
            $expiresAt = strtotime('+' . $gracePeriodDays . ' days', $expiresAt);
            if ($expiresAt === false) {
                throw new DomainException('Invalid license expiry');
            }
        }

        return $expiresAt;
    }

    private function isPlatformAllowed(array $license, string $platform): bool
    {
        $allowed = $this->policies->resolveAllowedPlatforms(
            isset($license['app_id']) ? (int) $license['app_id'] : null
        );
        return $allowed === [] || in_array(strtolower(trim($platform)), $allowed, true);
    }
}
