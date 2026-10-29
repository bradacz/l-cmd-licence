<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Request;
use App\Support\Env;

final class AuthService
{
    public function isAdminAuthorized(Request $request): bool
    {
        return $this->authorizedAdminTokenLabel($request) !== null;
    }

    public function authorizedAdminTokenLabel(Request $request): ?string
    {
        $auth = $request->header('authorization');
        if (!$auth || !str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($auth, 7));
        return $this->validateAdminToken($token);
    }

    public function validateAdminToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        foreach ($this->configuredAdminTokens() as $label => $expected) {
            if ($expected !== '' && hash_equals($expected, $token)) {
                return $label;
            }
        }

        return null;
    }

    public function tokenFingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }

    /** @return array<string,string> */
    private function configuredAdminTokens(): array
    {
        return [
            'primary' => (string) Env::get('ADMIN_BEARER_TOKEN', ''),
            'secondary' => (string) (Env::get('ADMIN_BEARER_TOKEN_PREVIOUS', Env::get('ADMIN_BEARER_TOKEN_SECONDARY', '')) ?? ''),
        ];
    }
}
