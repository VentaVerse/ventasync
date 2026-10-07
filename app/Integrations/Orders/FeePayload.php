<?php

namespace App\Integrations\Orders;

final class FeePayload
{
    public static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function amount(array $payload, string $key): float
    {
        // Money::parse strips thousands commas; a bare float cast would read 2,251.57 as 2.
        return abs(\App\Support\Money::parse($payload[$key] ?? 0));
    }

    public static function isEmpty(array $payload): bool
    {
        return $payload === [];
    }
}
