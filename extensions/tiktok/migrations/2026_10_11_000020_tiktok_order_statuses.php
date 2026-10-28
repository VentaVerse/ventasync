<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = [
        'UNPAID' => 11, 'ON_HOLD' => 1, 'AWAITING_SHIPMENT' => 1, 'AWAITING_COLLECTION' => 2, 'IN_TRANSIT' => 3,
        'DELIVERED' => 9, 'COMPLETED' => 10, 'CANCELLED' => 5,
    ];

    public function up(): void
    {
        if (DB::table('ch_tiktok_order_status_map')->exists()) {
            return;
        }
        $now = now();
        DB::table('ch_tiktok_order_status_map')->insert(array_map(fn ($status, $id) => [
            'tiktok_status' => $status, 'context' => 'order', 'order_status_id' => $id, 'created_at' => $now, 'updated_at' => $now,
        ], array_keys(self::STATUSES), self::STATUSES));
    }

    public function down(): void
    {
    }
};
