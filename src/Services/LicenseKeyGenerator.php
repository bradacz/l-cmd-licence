<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Env;
use InvalidArgumentException;

final class LicenseKeyGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRTUVWXY346789';

    public function generate(string $type, string $productName = '', ?string $productSlug = null): string
    {
        $typeCode = match ($type) {
            'lifetime' => 'LIFE',
            'annual' => 'YEAR',
            'monthly' => 'MNTH',
            'whitelabel' => 'WLAB',
            'trial' => 'DEMO',
            default => throw new InvalidArgumentException('Invalid type: ' . $type),
        };

        $prefix = $this->resolvePrefix($productName, $productSlug);

        $random = '';
        $len = strlen(self::ALPHABET);
        for ($i = 0; $i < 16; $i++) {
            $random .= self::ALPHABET[random_int(0, $len - 1)];
        }

        $blocks = str_split($random, 4);
        $payload = $prefix . '-' . $typeCode . '-' . implode('-', $blocks);
        $checksum = strtoupper(substr(hash('sha256', $payload . Env::get('LICENSE_SALT', '')), 0, 4));

        return $payload . '-' . $checksum;
    }

    public function validate(string $key): bool
    {
        if (!preg_match('/^[A-Z0-9]{4}-[A-Z]{4}-(?:[A-Z0-9]{4}-){4}[A-Z0-9]{4}$/', $key)) {
            return false;
        }

        $parts = explode('-', $key);
        $checksum = (string) array_pop($parts);
        $payload = implode('-', $parts);

        $expected = strtoupper(substr(hash('sha256', $payload . Env::get('LICENSE_SALT', '')), 0, 4));
        return hash_equals($expected, $checksum);
    }

    private function resolvePrefix(string $productName, ?string $productSlug = null): string
    {
        $candidates = [
            $productSlug ?? '',
            $productName,
        ];

        foreach ($candidates as $candidate) {
            $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $candidate) ?? '');
            if ($clean !== '') {
                return str_pad(substr($clean, 0, 4), 4, 'X');
            }
        }

        return 'LICN';
    }
}
