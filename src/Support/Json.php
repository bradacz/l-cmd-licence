<?php

declare(strict_types=1);

namespace App\Support;

final class Json
{
    /** @return array<string, mixed> */
    public static function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
