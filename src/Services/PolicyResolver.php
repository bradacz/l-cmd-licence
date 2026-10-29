<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PolicyRepository;

final class PolicyResolver
{
    public function __construct(
        private readonly PolicyRepository $policies = new PolicyRepository()
    ) {
    }

    public function resolveMaxActivations(?int $appId, int $legacyValue): int
    {
        $value = $this->resolvePolicyValue($appId, 'max_activations');
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return $legacyValue;
        }

        $resolved = (int) $value;
        return $resolved > 0 ? $resolved : $legacyValue;
    }

    public function resolveJwtTtlSeconds(?int $appId, int $legacyValue): int
    {
        $value = $this->resolvePolicyValue($appId, 'jwt_ttl_seconds');
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return $legacyValue;
        }

        $resolved = (int) $value;
        return $resolved > 0 ? $resolved : $legacyValue;
    }

    /** @return string[] */
    public function resolveEnabledFeatures(?int $appId, array $legacyFeatures): array
    {
        $value = $this->resolvePolicyValue($appId, 'enabled_features');
        if (!is_array($value)) {
            return $legacyFeatures;
        }

        $features = array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
            $value
        )));

        return array_values(array_unique($features));
    }

    public function resolveGracePeriodDays(?int $appId): ?int
    {
        $value = $this->resolvePolicyValue($appId, 'grace_period_days');
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        $resolved = (int) $value;
        return $resolved >= 0 ? $resolved : null;
    }

    /** @return string[] */
    public function resolveAllowedPlatforms(?int $appId): array
    {
        $value = $this->resolvePolicyValue($appId, 'allowed_platforms');
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? strtolower(trim($item)) : null,
            $value
        )));
    }

    private function resolvePolicyValue(?int $appId, string $policyKey): mixed
    {
        if ($appId !== null && $appId > 0) {
            $appPolicy = $this->policies->findAppPolicy($appId, $policyKey);
            if ($appPolicy !== null && (int) ($appPolicy['is_enabled'] ?? 0) === 1) {
                return $this->decodePolicyValue($appPolicy['policy_value'] ?? null);
            }
        }

        $systemPolicy = $this->policies->findSystemPolicy($policyKey);
        if ($systemPolicy !== null && (int) ($systemPolicy['is_enabled'] ?? 0) === 1) {
            return $this->decodePolicyValue($systemPolicy['policy_value'] ?? null);
        }

        return null;
    }

    private function decodePolicyValue(mixed $raw): mixed
    {
        if (!is_string($raw)) {
            return $raw;
        }

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
}
