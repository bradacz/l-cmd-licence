<?php

declare(strict_types=1);

namespace App\Support;

final class IntegrationGuideBuilder
{
    /** @return array<string,mixed> */
    public static function build(string $appSlug, ?string $appType = null, ?string $offlinePublicKeyUrl = null): array
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        $productsUrl = $base . '/api/v1/apps/' . rawurlencode($appSlug) . '/products';
        $checkoutUrl = $base . '/api/v1/apps/' . rawurlencode($appSlug) . '/checkout/create';
        $verifyUrl = $base . '/api/v1/apps/' . rawurlencode($appSlug) . '/license/verify';
        $activateUrl = $base . '/api/v1/apps/' . rawurlencode($appSlug) . '/license/activate';
        $deactivateUrl = $base . '/api/v1/apps/' . rawurlencode($appSlug) . '/license/deactivate';
        $normalizedType = self::normalizeType($appType);

        $applicationTitle = 'Application Setup';
        $applicationItems = [
            'Verify licenses via ' . $verifyUrl,
            'Activate devices via ' . $activateUrl,
            'Deactivate devices via ' . $deactivateUrl,
            'Send stable installation_hash, version metadata and platform metadata from the app',
        ];
        $checklist = [
            'Create at least one active client key and one server key.',
            'Bind products and tariffs to this application before launch.',
            'Test products, checkout, verify, activate and deactivate flows end to end.',
            'Confirm SMTP templates and webhook delivery before production launch.',
        ];
        $offlineItems = [
            'Request an offline token by sending metadata.request_offline_token=true during verify when the current tenant plan includes offline licenses.',
            'Validate the offline token locally with the exported public key only. Never ship a server secret with the client.',
        ];
        if ($offlinePublicKeyUrl !== null && $offlinePublicKeyUrl !== '') {
            $offlineItems[] = 'Fetch the offline verification public key from ' . $offlinePublicKeyUrl;
        }

        if ($normalizedType === 'unity') {
            $applicationTitle = 'Unity Setup';
            $applicationItems[] = 'Wrap verify, activate and deactivate calls in a lightweight C# service layer.';
            $applicationItems[] = 'Persist installation_hash and the short-lived token in a stable local storage location.';
            $applicationItems[] = 'Send metadata.engine=unity, metadata.engine_version and metadata.build_type with verify.';
            $checklist[] = 'Test the runtime flow in a development build and in a release build.';
            $checklist[] = 'Verify Unity startup flow handles expired, invalid and limit_exceeded states.';
        } elseif ($normalizedType === 'unreal') {
            $applicationTitle = 'Unreal Setup';
            $applicationItems[] = 'Wrap verify, activate and deactivate calls in a dedicated Unreal HTTP client service.';
            $applicationItems[] = 'Persist installation_hash and the short-lived token in a stable local storage location.';
            $applicationItems[] = 'Send metadata.engine=unreal, metadata.engine_version and metadata.build_type with verify.';
            $checklist[] = 'Test the runtime flow in editor and packaged builds.';
            $checklist[] = 'Verify Unreal startup flow handles expired, invalid and limit_exceeded states.';
        }

        return [
            'project_type' => $normalizedType,
            'website' => [
                'title' => 'Website Setup',
                'items' => [
                    'Load visible products from ' . $productsUrl,
                    'Create checkout sessions via ' . $checkoutUrl,
                    'Use the generated client public key in the website integration layer',
                    'Configure app-specific checkout success and cancel URLs in the license server before launch',
                ],
                'endpoints' => [
                    'products' => $productsUrl,
                    'checkout' => $checkoutUrl,
                ],
            ],
            'application' => [
                'title' => $applicationTitle,
                'items' => $applicationItems,
                'endpoints' => [
                    'verify' => $verifyUrl,
                    'activate' => $activateUrl,
                    'deactivate' => $deactivateUrl,
                ],
                'metadata_example' => self::metadataExample($normalizedType),
            ],
            'offline' => [
                'title' => 'Offline Verification',
                'items' => $offlineItems,
                'endpoints' => [
                    'public_key' => $offlinePublicKeyUrl,
                ],
            ],
            'checklist' => $checklist,
        ];
    }

    private static function normalizeType(?string $appType): string
    {
        $normalized = strtolower(trim((string) $appType));

        return in_array($normalized, ['desktop', 'web', 'mobile', 'unity', 'unreal'], true)
            ? $normalized
            : 'desktop';
    }

    /** @return array<string,string> */
    private static function metadataExample(string $appType): array
    {
        $base = [
            'platform' => $appType === 'web' ? 'web' : 'desktop',
            'app_version' => '1.0.0',
        ];

        if ($appType === 'unity') {
            return $base + [
                'engine' => 'unity',
                'engine_version' => '2022.3',
                'build_type' => 'release',
            ];
        }

        if ($appType === 'unreal') {
            return $base + [
                'engine' => 'unreal',
                'engine_version' => '5.4',
                'build_type' => 'shipping',
            ];
        }

        return $base;
    }
}
