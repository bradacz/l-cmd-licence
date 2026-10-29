<?php

declare(strict_types=1);

namespace App\Support;

final class AppCheckoutUrlConfig
{
    /** @return array{public_base_url:?string,checkout_success_url:?string,checkout_cancel_url:?string} */
    public static function normalize(
        ?string $publicBaseUrl,
        ?string $checkoutSuccessUrl,
        ?string $checkoutCancelUrl
    ): array {
        $normalizedBase = self::normalizeUrl($publicBaseUrl);
        $normalizedSuccess = self::normalizeUrl($checkoutSuccessUrl);
        $normalizedCancel = self::normalizeUrl($checkoutCancelUrl);

        if ($normalizedBase !== null && $normalizedSuccess === null) {
            $normalizedSuccess = $normalizedBase . '/checkout/success?session_id={CHECKOUT_SESSION_ID}';
        }

        if ($normalizedBase !== null && $normalizedCancel === null) {
            $normalizedCancel = $normalizedBase . '/checkout/cancel';
        }

        return [
            'public_base_url' => $normalizedBase,
            'checkout_success_url' => $normalizedSuccess,
            'checkout_cancel_url' => $normalizedCancel,
        ];
    }

    public static function validate(?string $checkoutSuccessUrl, ?string $checkoutCancelUrl): ?string
    {
        if ($checkoutSuccessUrl === null || $checkoutCancelUrl === null) {
            return 'Checkout return URLs are not configured for this app';
        }

        if (!self::isAbsoluteHttpsUrl($checkoutSuccessUrl)) {
            return 'Checkout success URL must be a valid HTTPS URL';
        }

        if (!str_contains($checkoutSuccessUrl, '{CHECKOUT_SESSION_ID}')) {
            return 'Checkout success URL must include {CHECKOUT_SESSION_ID}';
        }

        if (!self::isAbsoluteHttpsUrl($checkoutCancelUrl)) {
            return 'Checkout cancel URL must be a valid HTTPS URL';
        }

        if (str_contains($checkoutCancelUrl, '{CHECKOUT_SESSION_ID}')) {
            return 'Checkout cancel URL must not include {CHECKOUT_SESSION_ID}';
        }

        return null;
    }

    private static function normalizeUrl(?string $url): ?string
    {
        $value = trim((string) $url);
        if ($value === '') {
            return null;
        }

        return rtrim($value, '/');
    }

    private static function isAbsoluteHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
    }
}
