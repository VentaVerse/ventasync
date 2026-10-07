<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Support\Actor;

class StockHistoryLogger
{
    public static function log(
        int $productId,
        ?int $optionValueId,
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
            'product_option_value_id' => $optionValueId,
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

    public static function sourceFromOrder(string $marketplaceSource): string
    {
        if ($marketplaceSource === 'lazada') return 'lazada_sync';
        if ($marketplaceSource === 'shopee') return 'shopee_sync';
        if ($marketplaceSource === 'tiktok') return 'tiktok_sync';
        if (str_starts_with($marketplaceSource, 'opencart')) return 'opencart_sync';
        if (str_starts_with($marketplaceSource, 'ventacart')) return 'ventacart_sync';
        return 'order';
    }
}
