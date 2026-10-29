<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Json;
use App\Support\Url;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        /** @var array<string, string> */
        public readonly array $headers,
        /** @var array<string, mixed> */
        public readonly array $query,
        /** @var array<string, mixed> */
        public readonly array $post,
        public readonly string $rawBody,
        /** @var array<string, mixed> */
        public readonly array $json,
        public readonly string $ip,
        public readonly string $userAgent
    ) {
    }

    public static function capture(): self
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $rawBody = file_get_contents('php://input') ?: '';
        $json = Json::decodeObject($rawBody);

        $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = Url::stripBasePath((string) ($rawPath ?: '/'));
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path ?: '/',
            array_change_key_case($headers, CASE_LOWER),
            $_GET,
            $_POST,
            $rawBody,
            $json,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
