<?php

namespace App\Integrations\Listings;

use App\Services\ActivityLogger;

/**
 * A DROPPED LINK IS WRITTEN DOWN, WITH EVERY FACT THE DECISION RESTED ON.
 *
 * A link check that cannot find a listing on the store drops the link -
 * correctly, most of the time, because the item really is gone. The rest
 * of the time the store refused, or answered short, or the token had
 * expired, and dropping the link is a destructive act taken on bad
 * information.
 *
 * Until 2026-09-09 none of it left a trace. The owner, watching links vanish
 * while nobody touched the system: "i didnt do anything for a few mins...
 * and the products are unlinked." There was nothing to look at, and no
 * way at all to tell a correct drop from a wrong one.
 *
 * So every drop files the same four facts, in the same words on every
 * channel: which listing was dropped, which STORE was asked, what it was
 * searched by, and how much of that store was actually read. The last one
 * is the tell - a drop taken against an index of zero is a refusal
 * wearing the clothes of an answer.
 *
 * Filed with source 'api', because no human pressed it.
 */
final class DroppedLink
{
    /**
     * @param  string  $channel   the channel or store's name, as the operator reads it
     * @param  string  $model     the activity log's subject type, e.g. "Shopee Product"
     * @param  int|string  $itemId  the listing id on the store that is being let go
     * @param  int|string  $storeId the store or shop that was asked
     * @param  list<string>  $skus  what it was searched by
     * @param  int  $indexSize     how many listings the read of that store returned
     */
    public static function write(
        string $channel,
        string $model,
        int $productId,
        int|string $itemId,
        int|string $storeId,
        array $skus,
        int $indexSize,
        string $reason = 'the scheduled check found the item gone',
    ): void {
        $searched = array_values(array_filter(array_map('strval', $skus)));

        ActivityLogger::log(
            'updated',
            $model,
            $productId,
            'Link to ' . $channel . ' item ' . $itemId . ' dropped: store ' . $storeId
                . ' does not hold it, and no listing there carries '
                . ($searched === [] ? 'any code this product has' : implode(', ', array_slice($searched, 0, 5))) . '.',
            [
                'reason' => $reason,
                'item_id' => (string) $itemId,
                'store_id' => (string) $storeId,
                'searched_skus' => array_slice($searched, 0, 20),
                'shop_read_completely' => true,
                'items_in_shop_index' => $indexSize,
            ],
            'api'
        );
    }
}
