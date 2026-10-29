<?php

declare(strict_types=1);

use App\Http\ClientError;
use App\Services\CsrfService;
use App\Services\JwtService;
use App\Support\SecretBox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_ENV['APP_KEY']);
        putenv('APP_KEY=' . bin2hex(random_bytes(32)));
    }

    public function testSignedTokenRoundTripAndTamperRejection(): void
    {
        $jwt = new JwtService();
        $payload = ['license_id' => 17, 'exp' => time() + 60];
        $token = $jwt->create($payload);
        self::assertSame($payload, $jwt->verify($token));
        self::assertNull($jwt->verify($token . 'x'));
        self::assertNull($jwt->verify('bad.token'));
    }

    #[DataProvider('invalidClaims')]
    public function testInvalidOrExpiredClaimsAreRejected(array $payload): void
    {
        $jwt = new JwtService();
        self::assertNull($jwt->verify($jwt->create($payload)));
    }

    public static function invalidClaims(): array
    {
        return [
            'missing expiry' => [[]],
            'expired' => [['exp' => 1]],
            'exact expiry boundary' => [['exp' => time()]],
            'string expiry' => [['exp' => (string) (time() + 600)]],
            'null expiry' => [['exp' => null]],
            'future not-before' => [['exp' => time() + 600, 'nbf' => time() + 300]],
            'invalid not-before' => [['exp' => time() + 600, 'nbf' => 'tomorrow']],
        ];
    }

    public function testAlgorithmAndJsonObjectAreValidated(): void
    {
        $jwt = new JwtService();
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        foreach ([['alg' => 'none'], ['alg' => 'HS512'], ['alg' => 'HS256', 'typ' => 'other']] as $header) {
            $data = $encode(json_encode($header)) . '.' . $encode(json_encode(['exp' => time() + 60]));
            $sig = $encode(hash_hmac('sha256', $data, getenv('APP_KEY'), true));
            self::assertNull($jwt->verify($data . '.' . $sig));
        }
        $data = $encode('{"alg":"HS256","typ":"JWT"}') . '.' . $encode('[1,2,3]');
        self::assertNull($jwt->verify($data . '.' . $encode(hash_hmac('sha256', $data, getenv('APP_KEY'), true))));
    }

    #[DataProvider('shortKeys')]
    public function testSigningWithoutStrongConfiguredKeyFails(string $key): void
    {
        putenv('APP_KEY=' . $key);
        $this->expectException(RuntimeException::class);
        (new JwtService())->create(['exp' => time() + 60]);
    }

    public static function shortKeys(): array
    {
        return [[''], ['short'], [str_repeat(' ', 64)], [str_repeat('x', 31)]];
    }

    public function testEmptyKeyCannotDecryptOrEncrypt(): void
    {
        $cipher = SecretBox::encrypt('private-value');
        putenv('APP_KEY=');
        try {
            SecretBox::decrypt($cipher);
            self::fail('Decryption must fail closed.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
        $this->expectException(RuntimeException::class);
        SecretBox::encrypt('private-value');
    }

    public function testAuthenticatedEncryptionRoundTripAndTampering(): void
    {
        $encrypted = SecretBox::encrypt(' sensitive value ');
        self::assertSame(' sensitive value ', SecretBox::decrypt($encrypted));
        self::assertFalse(SecretBox::needsReencrypt($encrypted));
        [$prefix, $encoded] = explode(':', $encrypted, 2);
        $raw = base64_decode($encoded);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        self::assertNull(SecretBox::decrypt($prefix . ':' . base64_encode($raw)));
        self::assertNull(SecretBox::decrypt('sbx2:' . base64_encode(str_repeat('a', 25))));
        self::assertNull(SecretBox::decrypt('gcm1:malformed'));
    }

    public function testLegacyCiphertextRemainsReadableForMigration(): void
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt('legacy-value', 'aes-256-cbc', hash('sha256', getenv('APP_KEY'), true), OPENSSL_RAW_DATA, $iv);
        $legacy = base64_encode($iv . $encrypted);
        self::assertTrue(SecretBox::needsReencrypt($legacy));
        self::assertSame('legacy-value', SecretBox::decrypt($legacy));
    }

    public function testCsrfRejectsEmptyTokens(): void
    {
        $_SESSION = ['csrf_token' => ''];
        self::assertFalse(CsrfService::verify(''));
        self::assertFalse(CsrfService::verify(null));
        $token = CsrfService::token();
        self::assertTrue(CsrfService::verify($token));
        self::assertFalse(CsrfService::verify($token . 'x'));
    }

    public function testInternalErrorsAreNotPublic(): void
    {
        self::assertSame('Unable to complete request', ClientError::message(new PDOException('database password=DO-NOT-DISCLOSE')));
        self::assertSame('Unable to complete request', ClientError::message(new RuntimeException('provider-secret=DO-NOT-DISCLOSE')));
        self::assertSame('Platform not allowed', ClientError::message(new DomainException('Platform not allowed')));
    }
}
