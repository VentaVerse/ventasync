<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lazada_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->string('lazada_status', 64)->unique();
            $table->unsignedInteger('order_status_id');
            $table->timestamps();
        });

        // Seed with current defaults
        $now = now();
        DB::table('lazada_order_status_map')->insert([
            ['lazada_status' => 'unpaid',        'order_status_id' => 11, 'created_at' => $now, 'updated_at' => $now],
            ['lazada_status' => 'pending',       'order_status_id' => 1,  'created_at' => $now, 'updated_at' => $now],
            ['lazada_status' => 'packed',        'order_status_id' => 2,  'created_at' => $now, 'updated_at' => $now],
            ['lazada_status' => 'ready_to_ship', 'order_status_id' => 2,  'created_at' => $now, 'updated_at' => $now],
            ['lazada_status' => 'shipped',       'order_status_id' => 3,  'created_at' => $now, 'updated_at' => $now],
            ['lazada_status' => 'delivered',     'order_status_id' => 9,  'created_at' => $now, 'updated_at' => $now],
            ['lazada_status' => 'canceled',      'order_status_id' => 5,  'created_at' => $now, 'updated_at' => $now],
            ['lazada_status' => 'cancelled',     'order_status_id' => 5,  'created_at' => $now, 'updated_at' => $now],
            ['lazada_status' => 'returned',      'order_status_id' => 7,  'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_order_status_map');
    }
};
