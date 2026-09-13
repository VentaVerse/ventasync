<?php

namespace App\Integrations\Orders;

/**
 * Small helpers every channel's fee mapping needs, so four channels do not
 * write four subtly different ways of reading a number out of a payload
 * that may be a JSON string, an array, or missing entirely.
 */
final class FeePayload
{
    /** @return array<string, mixed> */
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

    /** A magnitude, whichever sign the marketplace used for a deduction. */
    public static function amount(array $payload, string $key): float
    {
        // Money::parse strips the thousands comma a marketplace may send
        // ("2,251.57"); a bare (float) would read that as 2 and understate the fee.
        return abs(\App\Support\Money::parse($payload[$key] ?? 0));
    }

    /**
     * Whether anything in this payload is a figure at all. A channel row
     * that exists with an empty payload must answer null rather than a set
     * of zeroes: "nothing recorded" and "charged nothing" are different
     * facts and only one of them should stop the normalizer writing.
     */
    public static function isEmpty(array $payload): bool
    {
        return $payload === [];
    }
}
