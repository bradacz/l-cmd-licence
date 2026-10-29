<?php

declare(strict_types=1);

namespace App\Services;

final class GoogleAnalyticsService
{
    public function normalizeMeasurementId(?string $measurementId): ?string
    {
        $value = strtoupper(trim((string) $measurementId));
        return $value !== '' ? $value : null;
    }

    public function isValidMeasurementId(?string $measurementId): bool
    {
        if ($measurementId === null || $measurementId === '') {
            return true;
        }

        return (bool) preg_match('/^G-[A-Z0-9]+$/', $measurementId);
    }

    /** @return array<int,string> */
    public function integrationChecklist(): array
    {
        return [
            'Vlozte Google Analytics tag do verejneho webu dane aplikace.',
            'Overte pageview a zakladni eventy primo v Google Analytics.',
            'Napojte checkout flow na spravnou domenu a aplikaci.',
            'Prohlizejte analyticka data primo v Google Analytics, ne v LCMS.',
        ];
    }
}
