<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopee_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->string('shopee_status', 64)->unique();
            $table->unsignedInteger('order_status_id');
            $table->timestamps();
        });

        $now = now();
        DB::table('shopee_order_status_map')->insert([
            ['shopee_status' => 'UNPAID',           'order_status_id' => 11, 'created_at' => $now, 'updated_at' => $now],
            ['shopee_status' => 'READY_TO_SHIP',    'order_status_id' => 1,  'created_at' => $now, 'updated_at' => $now],
            ['shopee_status' => 'PROCESSED',        'order_status_id' => 2,  'created_at' => $now, 'updated_at' => $now],
            ['shopee_status' => 'SHIPPED',          'order_status_id' => 3,  'created_at' => $now, 'updated_at' => $now],
            ['shopee_status' => 'COMPLETED',        'order_status_id' => 9,  'created_at' => $now, 'updated_at' => $now],
            ['shopee_status' => 'CANCELLED',        'order_status_id' => 5,  'created_at' => $now, 'updated_at' => $now],
            ['shopee_status' => 'IN_CANCEL',        'order_status_id' => 5,  'created_at' => $now, 'updated_at' => $now],
            ['shopee_status' => 'INVOICE_PENDING',  'order_status_id' => 1,  'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_order_status_map');
    }
};
