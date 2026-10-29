<?php

declare(strict_types=1);

namespace App\Support;

final class CheckoutMetadata
{
    public static function append(array $form, array $extra): array
    {
        foreach ($extra as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_]{1,40}$/D', $key) || !is_scalar($value)) {
                continue;
            }
            $field = 'metadata[' . $key . ']';
            // Pricing, product and tenant metadata must only come from server validation.
            if (!array_key_exists($field, $form)) {
                $form[$field] = mb_substr((string) $value, 0, 500);
            }
        }
        return $form;
    }
}
