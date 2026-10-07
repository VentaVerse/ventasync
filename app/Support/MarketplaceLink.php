<?php

namespace App\Support;

class MarketplaceLink
{
    // Keep narrow: a timeout or auth failure must not read as missing, or a duplicate listing is offered.
    private const MISSING = [
        'product not found',
        'variant not found',
        'item_not_found',
        'is not found',
        'error_item_not_found',
        'product_not_exist',
        'does not exist',
        '404',
    ];

    public static function looksMissingRemotely(?string $error): bool
    {
        $error = strtolower(trim((string) $error));

        if ($error === '') {
            return false;
        }

        foreach (self::MISSING as $needle) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
