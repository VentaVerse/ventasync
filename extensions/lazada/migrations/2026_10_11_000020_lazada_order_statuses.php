<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = [
        'unpaid' => 11, 'pending' => 1, 'packed' => 2, 'repacked' => 2, 'ready_to_ship' => 2, 'shipped' => 3,
        'lost_by_3pl' => 3, 'damaged_by_3pl' => 3, 'delivered' => 9, 'confirmed' => 10, 'canceled' => 5, 'cancelled' => 5,
        'failed_delivery' => 8,
    ];

    public function up(): void
    {
        if (DB::table('ch_lazada_order_status_map')->exists()) {
            return;
        }
        $now = now();
        DB::table('ch_lazada_order_status_map')->insert(array_map(fn ($status, $id) => [
            'lazada_status' => $status, 'context' => 'order', 'order_status_id' => $id, 'created_at' => $now, 'updated_at' => $now,
        ], array_keys(self::STATUSES), self::STATUSES));
    }

    public function down(): void
    {
    }
};
