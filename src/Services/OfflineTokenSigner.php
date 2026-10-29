<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TenantSettingsRepository;
use App\Support\SecretBox;
use RuntimeException;

final class OfflineTokenSigner
{
    private const PUBLIC_KEY_SETTING = 'offline_public_key_pem';
    private const PRIVATE_KEY_SETTING = 'offline_private_key_pem';
    private const KEY_ID_SETTING = 'offline_key_id';

    public function issue(int $tenantId, array $payload): string
    {
        $keys = $this->ensureKeyPair($tenantId);
        $header = $this->base64urlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $keys['key_id'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $body = $this->base64urlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $signingInput = $header . '.' . $body;

        $privateKey = openssl_pkey_get_private($keys['private_key_pem']);
        if ($privateKey === false) {
            throw new RuntimeException('Offline signing key is invalid.');
        }

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (is_resource($privateKey)) {
            openssl_free_key($privateKey);
        }
        if ($signed !== true) {
            throw new RuntimeException('Unable to sign offline token.');
        }

        return $signingInput . '.' . $this->base64urlEncode($signature);
    }

    /** @return array{public_key_pem:string,key_id:string} */
    public function publicKeyData(int $tenantId): array
    {
        $keys = $this->ensureKeyPair($tenantId);

        return [
            'public_key_pem' => $keys['public_key_pem'],
            'key_id' => $keys['key_id'],
        ];
    }

    /** @return array{public_key_pem:string,private_key_pem:string,key_id:string} */
    private function ensureKeyPair(int $tenantId): array
    {
        $work = function () use ($tenantId): array {
            $stmt = \App\Database::pdo()->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
            $stmt->execute([$tenantId]);
            if ($stmt->fetchColumn() === false) {
                throw new RuntimeException('Offline signing tenant does not exist.');
            }
            return $this->loadOrCreateKeyPair($tenantId);
        };
        return \App\Database::pdo()->inTransaction() ? $work() : \App\Database::transaction($work);
    }

    private function loadOrCreateKeyPair(int $tenantId): array
    {
        $settings = new TenantSettingsRepository();
        $values = $settings->allForTenant($tenantId, true);
        $publicKeyPem = trim((string) ($values[self::PUBLIC_KEY_SETTING] ?? ''));
        if ($publicKeyPem !== '') {
            $publicKeyPem .= "\n";
        }
        $privateKeyCiphertext = (string) ($values[self::PRIVATE_KEY_SETTING] ?? '');
        $privateKeyPem = SecretBox::decrypt($privateKeyCiphertext);
        $keyId = trim((string) ($values[self::KEY_ID_SETTING] ?? ''));

        if ($publicKeyPem !== '' && $privateKeyPem !== null && $privateKeyPem !== '') {
            $privateKey = openssl_pkey_get_private($privateKeyPem);
            $details = $privateKey === false ? false : openssl_pkey_get_details($privateKey);
            if (!is_array($details) || trim((string) ($details['key'] ?? '')) !== trim($publicKeyPem)) {
                throw new RuntimeException('Stored offline signing key pair is inconsistent.');
            }
            if (SecretBox::needsReencrypt($privateKeyCiphertext)) {
                $settings->upsert($tenantId, self::PRIVATE_KEY_SETTING, SecretBox::encrypt($privateKeyPem));
            }

            return [
                'public_key_pem' => $publicKeyPem,
                'private_key_pem' => $privateKeyPem,
                'key_id' => $keyId !== '' ? $keyId : $this->keyIdForPublicKey($publicKeyPem),
            ];
        }

        // A bad APP_KEY or damaged record must not silently invalidate existing tokens.
        if ($publicKeyPem !== '' || $privateKeyCiphertext !== '' || $keyId !== '') {
            throw new RuntimeException('Stored offline signing keys cannot be loaded. Restore the original keys.');
        }

        $generated = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($generated === false) {
            throw new RuntimeException('Unable to generate offline signing key pair.');
        }

        $privateKeyOut = '';
        if (openssl_pkey_export($generated, $privateKeyOut) !== true || $privateKeyOut === '') {
            throw new RuntimeException('Unable to export offline private key.');
        }

        $details = openssl_pkey_get_details($generated);
        if (!is_array($details) || empty($details['key'])) {
            throw new RuntimeException('Unable to export offline public key.');
        }

        $publicKeyOut = (string) $details['key'];
        $resolvedKeyId = $this->keyIdForPublicKey($publicKeyOut);

        $settings->upsert($tenantId, self::PUBLIC_KEY_SETTING, $publicKeyOut);
        $settings->upsert($tenantId, self::PRIVATE_KEY_SETTING, SecretBox::encrypt($privateKeyOut));
        $settings->upsert($tenantId, self::KEY_ID_SETTING, $resolvedKeyId);

        return [
            'public_key_pem' => $publicKeyOut,
            'private_key_pem' => $privateKeyOut,
            'key_id' => $resolvedKeyId,
        ];
    }

    private function keyIdForPublicKey(string $publicKeyPem): string
    {
        return 'off_' . substr(hash('sha256', $publicKeyPem), 0, 16);
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
