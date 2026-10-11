<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Support\Actor;

class StockHistoryLogger
{
    public static function log(
        int $productId,
        ?int $variationValueId,
        ?int $orderId,
        string $type,
        int $qtyBefore,
        int $qtyAfter,
        string $source,
        ?string $note = null,
        ?int $userId = null,
        ?string $userName = null,
        ?int $warehouseId = null,
    ): void {
        if ($userId === null) {
            $actor = Actor::current();
            $userId = $actor->userId;
            $userName = $userName ?? $actor->name;
        }

        $source = \App\Support\AppDoor::source($source);

        DB::table('stock_history')->insert([
            'product_id'              => $productId,
            'product_variation_value_id' => $variationValueId,
            'order_id'                => $orderId,
            'type'                    => $type,
            'quantity_before'         => $qtyBefore,
            'quantity_after'          => $qtyAfter,
            'quantity_change'         => $qtyAfter - $qtyBefore,
            'source'                  => $source,
            'note'                    => $note,
            'user_id'                 => $userId,
            'user_name'               => $userName,
            'warehouse_id'            => $warehouseId,
            'created_at'              => now(),
        ]);
    }

    public static function sourceFromOrder(string $orderChannel): string
    {
        if ($orderChannel === 'lazada') return 'lazada_sync';
        if ($orderChannel === 'shopee') return 'shopee_sync';
        if ($orderChannel === 'tiktok') return 'tiktok_sync';
        if (str_starts_with($orderChannel, 'opencart')) return 'opencart_sync';
        if (str_starts_with($orderChannel, 'ventacart')) return 'ventacart_sync';
        return 'order';
    }
}
