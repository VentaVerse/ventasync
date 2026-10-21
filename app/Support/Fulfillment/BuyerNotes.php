<?php

namespace App\Support\Fulfillment;

use Illuminate\Support\Facades\DB;

final class BuyerNotes
{
    private static array $notes = [];

    public static function prime(iterable $orderIds): void
    {
        $ids = [];
        foreach ($orderIds as $id) {
            $id = (int) $id;
            if ($id > 0 && ! array_key_exists($id, self::$notes)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return;
        }
        foreach (array_chunk(array_unique($ids), 500) as $chunk) {
            $found = DB::table('orders')->whereIn('order_id', $chunk)->pluck('customer_note', 'order_id');
            foreach ($chunk as $id) {
                self::$notes[$id] = trim((string) ($found[$id] ?? ''));
            }
        }
    }

    public static function of(mixed $orderId): string
    {
        $id = (int) $orderId;
        if ($id <= 0) {
            return '';
        }
        self::prime([$id]);

        return self::$notes[$id] ?? '';
    }

    public static function forget(): void
    {
        self::$notes = [];
    }
}
