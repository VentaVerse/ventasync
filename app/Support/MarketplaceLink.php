<?php

namespace App\Support;

/**
 * Whether a marketplace has told us a linked product is not there.
 *
 * A link row is the ERP's own record that a product exists on a channel. Every
 * page treats it as proof: hold a link and the row offers Update, Push stock,
 * Push price, Delete; hold none and it offers Match and Push. That works right
 * up until the link is WRONG, and then the interface offers only the actions
 * that cannot succeed and withholds the one that would.
 *
 * A link goes wrong more often than it sounds. A database copied between
 * environments carries links to products the new environment never had. A
 * product deleted at the marketplace leaves its link behind. Both have
 * happened on this installation: five Venta products and one Shopee item.
 *
 * The marketplaces say so plainly, each in their own words, so the recorded
 * failure is read rather than the link second-guessed.
 */
class MarketplaceLink
{
    /**
     * Phrases that mean "this does not exist here", across the channels.
     *
     * Deliberately narrow. A timeout, a rate limit or an auth failure must NOT
     * match: they are reasons to retry, and treating them as a missing product
     * would offer to recreate something that is present, which on a live
     * storefront means a duplicate listing.
     */
    private const MISSING = [
        'product not found',
        'variant not found',
        'item_not_found',
        // Shopee's own wording, verified against a real response:
        // "Item_id is not found."
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
