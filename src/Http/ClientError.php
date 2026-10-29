<?php

declare(strict_types=1);

namespace App\Http;

use DomainException;
use Throwable;

final class ClientError
{
    public static function message(Throwable $error): string
    {
        if ($error instanceof DomainException) {
            return $error->getMessage();
        }

        // Do not log exception text: drivers and providers can embed secrets.
        error_log('[request-failed] ' . $error::class . ' code=' . $error->getCode());
        return 'Unable to complete request';
    }

    public static function respond(Throwable $error, bool $html = false): void
    {
        error_log('[request-failed] ' . $error::class . ' code=' . $error->getCode());
        if ($html) {
            Response::html('<h1>Internal Server Error</h1>', 500);
        } else {
            Response::json(['error' => 'Internal Server Error'], 500);
        }
    }
}
