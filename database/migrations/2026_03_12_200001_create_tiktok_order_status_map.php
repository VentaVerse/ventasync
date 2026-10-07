<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tiktok_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->string('tiktok_status', 64);
            $table->string('context', 16)->default('order');
            $table->unsignedInteger('order_status_id');
            $table->timestamps();

            $table->unique(['tiktok_status', 'context']);
        });

        $now = now();
        DB::table('tiktok_order_status_map')->insert([
            ['tiktok_status' => 'UNPAID',              'context' => 'order', 'order_status_id' => 11, 'created_at' => $now, 'updated_at' => $now],
            ['tiktok_status' => 'AWAITING_SHIPMENT',   'context' => 'order', 'order_status_id' => 1,  'created_at' => $now, 'updated_at' => $now],
            ['tiktok_status' => 'AWAITING_COLLECTION', 'context' => 'order', 'order_status_id' => 2,  'created_at' => $now, 'updated_at' => $now],
            ['tiktok_status' => 'IN_TRANSIT',          'context' => 'order', 'order_status_id' => 3,  'created_at' => $now, 'updated_at' => $now],
            ['tiktok_status' => 'DELIVERED',           'context' => 'order', 'order_status_id' => 9,  'created_at' => $now, 'updated_at' => $now],
            ['tiktok_status' => 'COMPLETED',           'context' => 'order', 'order_status_id' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['tiktok_status' => 'CANCELLED',           'context' => 'order', 'order_status_id' => 5,  'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_order_status_map');
    }
};
