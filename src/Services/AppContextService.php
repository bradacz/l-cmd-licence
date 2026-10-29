<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppContextRepository;
use App\Repositories\AppRepository;
use App\Repositories\LicenseRepository;
use RuntimeException;

final class AppContextService
{
    public function __construct(
        private readonly AppRepository $apps = new AppRepository(),
        private readonly LicenseRepository $licenses = new LicenseRepository(),
        private readonly AppContextRepository $context = new AppContextRepository()
    ) {
    }

    /** @return array<string,mixed> */
    public function requireActiveApp(string $appSlug): array
    {
        $app = $this->apps->findBySlug($appSlug);
        if ($app === null || (int) ($app['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('APP_NOT_FOUND');
        }

        return $app;
    }

    public function assertLicenseBelongsToApp(string $appSlug, string $licenseKey): void
    {
        $license = $this->licenses->findByKey($licenseKey);
        if ($license === null) {
            throw new RuntimeException('LICENSE_NOT_FOUND');
        }

        if (!empty($license['app_slug'])) {
            if ((string) $license['app_slug'] !== $appSlug) {
                throw new RuntimeException('LICENSE_APP_MISMATCH');
            }

            return;
        }

        $productSlug = (string) ($license['product_slug'] ?? '');
        if (!$this->context->appOwnsProductSlug($appSlug, $productSlug)) {
            throw new RuntimeException('LICENSE_APP_MISMATCH');
        }
    }
}
