<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class ApplicationKey
{
    public static function get(): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if (strlen(trim($key)) < 32) {
            throw new RuntimeException('APP_KEY must contain at least 32 characters.');
        }

        return $key;
    }
}
