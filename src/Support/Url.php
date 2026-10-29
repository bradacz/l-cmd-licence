<?php

declare(strict_types=1);

namespace App\Support;

final class Url
{
    public static function basePath(): string
    {
        $basePath = trim((string) Env::get('APP_BASE_PATH', ''));
        if ($basePath === '' || $basePath === '/') {
            return '';
        }

        if (!str_starts_with($basePath, '/')) {
            $basePath = '/' . $basePath;
        }

        return rtrim($basePath, '/');
    }

    public static function path(string $path): string
    {
        $normalized = '/' . ltrim($path, '/');
        $basePath = self::basePath();
        return $basePath === '' ? $normalized : $basePath . $normalized;
    }

    public static function stripBasePath(string $path): string
    {
        $basePath = self::basePath();
        if ($basePath === '') {
            return $path;
        }

        if ($path === $basePath) {
            return '/';
        }

        if (str_starts_with($path, $basePath . '/')) {
            $trimmed = substr($path, strlen($basePath));
            return $trimmed === '' ? '/' : $trimmed;
        }

        return $path;
    }
}
