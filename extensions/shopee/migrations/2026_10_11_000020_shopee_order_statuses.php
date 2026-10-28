<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = [
        'UNPAID' => 11, 'READY_TO_SHIP' => 1, 'PROCESSED' => 2, 'SHIPPED' => 3, 'COMPLETED' => 9, 'CANCELLED' => 5,
        'IN_CANCEL' => 1, 'RETRY_SHIP' => 3, 'TO_CONFIRM_RECEIVE' => 9, 'TO_RETURN' => 3, 'INVOICE_PENDING' => 1,
    ];

    public function up(): void
    {
        if (DB::table('ch_shopee_order_status_map')->exists()) {
            return;
        }
        $now = now();
        DB::table('ch_shopee_order_status_map')->insert(array_map(fn ($status, $id) => [
            'shopee_status' => $status, 'context' => 'order', 'order_status_id' => $id, 'created_at' => $now, 'updated_at' => $now,
        ], array_keys(self::STATUSES), self::STATUSES));
    }

    public function down(): void
    {
    }
};
