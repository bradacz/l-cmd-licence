<?php

declare(strict_types=1);

namespace App\Support;

final class CurrencyCatalog
{
    /** @return string[] */
    public static function all(): array
    {
        return ['CZK', 'EUR', 'USD'];
    }

    public static function normalize(?string $currency): ?string
    {
        if ($currency === null) {
            return null;
        }

        $normalized = strtoupper(trim($currency));

        return in_array($normalized, self::all(), true) ? $normalized : null;
    }
}
