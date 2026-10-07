<?php

namespace App\Integrations\Listings;

use App\Services\ActivityLogger;

final class DroppedLink
{
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
