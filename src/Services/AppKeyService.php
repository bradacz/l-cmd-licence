<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppKeyRepository;

final class AppKeyService
{
    public function __construct(
        private readonly AppKeyRepository $keys = new AppKeyRepository()
    ) {
    }

    /** @return array{id:int,key_id:string,secret:string,last4:string,key_type:string,key_name:string} */
    public function createServerSecret(int $appId, string $keyName): array
    {
        $secret = $this->generateToken('lcms_srv');
        $keyId = $this->generateKeyId('srv');
        $last4 = substr($secret, -4);

        $id = $this->keys->create([
            'app_id' => $appId,
            'key_name' => $keyName,
            'key_type' => 'server_secret',
            'key_id' => $keyId,
            'secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
            'last4' => $last4,
            'is_active' => true,
        ]);

        return [
            'id' => $id,
            'key_id' => $keyId,
            'secret' => $secret,
            'last4' => $last4,
            'key_type' => 'server_secret',
            'key_name' => $keyName,
        ];
    }

    /** @return array{id:int,key_id:string,public_key:string,last4:string,key_type:string,key_name:string} */
    public function createClientKey(int $appId, string $keyName): array
    {
        $publicKey = $this->generateToken('lcms_pub');
        $keyId = $this->generateKeyId('pub');
        $last4 = substr($publicKey, -4);

        $id = $this->keys->create([
            'app_id' => $appId,
            'key_name' => $keyName,
            'key_type' => 'client_public',
            'key_id' => $keyId,
            'public_key' => $publicKey,
            'last4' => $last4,
            'is_active' => true,
        ]);

        return [
            'id' => $id,
            'key_id' => $keyId,
            'public_key' => $publicKey,
            'last4' => $last4,
            'key_type' => 'client_public',
            'key_name' => $keyName,
        ];
    }

    /** @return array<string,mixed> */
    public function rotate(array $existingKey): array
    {
        $this->keys->deactivate((int) $existingKey['id']);

        return (string) $existingKey['key_type'] === 'server_secret'
            ? $this->createServerSecret((int) $existingKey['app_id'], (string) $existingKey['key_name'])
            : $this->createClientKey((int) $existingKey['app_id'], (string) $existingKey['key_name']);
    }

    private function generateKeyId(string $type): string
    {
        return 'ak_' . $type . '_' . bin2hex(random_bytes(6));
    }

    private function generateToken(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }
}
