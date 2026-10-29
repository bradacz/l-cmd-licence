<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ApplicationKey;

final class JwtService
{
    public function create(array $payload): string
    {
        $header = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = self::base64url(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = self::base64url(
            hash_hmac('sha256', $header . '.' . $body, $this->secret(), true)
        );

        return $header . '.' . $body . '.' . $signature;
    }

    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $body, $signature] = $parts;

        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z0-9_-]+$/D', $part) !== 1) {
                return null;
            }
        }

        $decodedHeader = self::decodeObject($header);
        if (($decodedHeader['alg'] ?? null) !== 'HS256' || ($decodedHeader['typ'] ?? null) !== 'JWT') {
            return null;
        }

        $expected = self::base64url(
            hash_hmac('sha256', $header . '.' . $body, $this->secret(), true)
        );

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = self::decodeObject($body);
        if ($payload === null) {
            return null;
        }

        // These are expiring license tokens, not arbitrary unsigned claim bags.
        if (!isset($payload['exp']) || !is_int($payload['exp']) || $payload['exp'] <= time()) {
            return null;
        }
        if (array_key_exists('nbf', $payload) && (!is_int($payload['nbf']) || $payload['nbf'] > time())) {
            return null;
        }

        return $payload;
    }

    private function secret(): string
    {
        return ApplicationKey::get();
    }

    private static function decodeObject(string $value): ?array
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || self::base64url($decoded) !== $value) {
            return null;
        }
        $object = json_decode($decoded);

        return $object instanceof \stdClass ? json_decode($decoded, true) : null;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
