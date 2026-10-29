<?php

declare(strict_types=1);

namespace App\Support;

final class SecretBox
{
    private const SODIUM_PREFIX = 'sbx2:';
    private const GCM_PREFIX = 'gcm1:';

    public static function encrypt(?string $plainText): ?string
    {
        if ($plainText === null || $plainText === '') {
            return null;
        }

        $key = self::key();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plainText, $nonce, $key);
            return self::SODIUM_PREFIX . base64_encode($nonce . $cipher);
        }

        if (in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false || $tag === '') {
                throw new \RuntimeException('Authenticated encryption failed.');
            }

            return self::GCM_PREFIX . base64_encode($iv . $tag . $cipher);
        }

        throw new \RuntimeException('Authenticated encryption is unavailable.');
    }

    public static function decrypt(?string $cipherText): ?string
    {
        if ($cipherText === null || $cipherText === '') {
            return null;
        }

        if (str_starts_with($cipherText, self::SODIUM_PREFIX)) {
            return self::decryptSodium(substr($cipherText, strlen(self::SODIUM_PREFIX)));
        }

        if (str_starts_with($cipherText, self::GCM_PREFIX)) {
            return self::decryptGcm(substr($cipherText, strlen(self::GCM_PREFIX)));
        }

        return self::decryptLegacy($cipherText);
    }

    public static function needsReencrypt(?string $cipherText): bool
    {
        if ($cipherText === null || $cipherText === '') {
            return false;
        }

        return !str_starts_with($cipherText, self::SODIUM_PREFIX) && !str_starts_with($cipherText, self::GCM_PREFIX);
    }

    private static function decryptSodium(string $encoded): ?string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return $plain === false ? null : $plain;
    }

    private static function decryptGcm(string $encoded): ?string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) <= 28) {
            return null;
        }

        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $cipher = substr($decoded, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    private static function decryptLegacy(string $cipherText): ?string
    {
        $decoded = base64_decode($cipherText, true);
        if ($decoded === false || strlen($decoded) <= 16) {
            return null;
        }

        $iv = substr($decoded, 0, 16);
        $cipher = substr($decoded, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        return hash('sha256', ApplicationKey::get(), true);
    }
}
