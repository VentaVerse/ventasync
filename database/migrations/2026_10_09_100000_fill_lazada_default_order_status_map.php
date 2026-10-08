<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const DEFAULTS = [
        'unpaid' => 11,
        'pending' => 1,
        'packed' => 2,
        'repacked' => 2,
        'ready_to_ship' => 2,
        'shipped' => 3,
        'lost_by_3pl' => 3,
        'damaged_by_3pl' => 3,
        'delivered' => 9,
        'confirmed' => 10,
        'canceled' => 5,
        'cancelled' => 5,
        'failed_delivery' => 8,
    ];

    public function up(): void
    {
        if (DB::table('lazada_order_status_map')->where('context', 'order')->exists()) {
            return;
        }

        $now = now();
        DB::table('lazada_order_status_map')->insert(array_map(
            fn (string $status, int $id) => ['lazada_status' => $status, 'context' => 'order', 'order_status_id' => $id, 'created_at' => $now, 'updated_at' => $now],
            array_keys(self::DEFAULTS),
            self::DEFAULTS,
        ));
    }
};
