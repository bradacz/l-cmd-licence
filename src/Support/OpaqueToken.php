<?php

declare(strict_types=1);

namespace App\Support;

final class OpaqueToken
{
    public static function generate(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }

    public static function hash(string $token): string
    {
        $secret = Env::get('APP_KEY') ?? Env::get('LICENSE_SALT') ?? 'lcms-token';

        return hash_hmac('sha256', $token, $secret);
    }
}
