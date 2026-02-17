<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('catalog.prefix');
        $table = $p.'order_status';

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) {
            $t->integer('order_status_id')->autoIncrement();
            $t->integer('language_id')->default(1);
            $t->string('name', 32);
            $t->tinyInteger('subtract_stock')->default(0);
        });

        // Seed default statuses
        DB::table($table)->insert([
            ['order_status_id' => 1,  'language_id' => 1, 'name' => 'Pending',          'subtract_stock' => 0],
            ['order_status_id' => 2,  'language_id' => 1, 'name' => 'Processing',       'subtract_stock' => 1],
            ['order_status_id' => 3,  'language_id' => 1, 'name' => 'Shipped',          'subtract_stock' => 0],
            ['order_status_id' => 4,  'language_id' => 1, 'name' => 'Complete',         'subtract_stock' => 0],
            ['order_status_id' => 5,  'language_id' => 1, 'name' => 'Cancelled',        'subtract_stock' => 0],
            ['order_status_id' => 6,  'language_id' => 1, 'name' => 'Denied',           'subtract_stock' => 0],
            ['order_status_id' => 7,  'language_id' => 1, 'name' => 'Reversed',         'subtract_stock' => 0],
            ['order_status_id' => 8,  'language_id' => 1, 'name' => 'Failed',           'subtract_stock' => 0],
            ['order_status_id' => 9,  'language_id' => 1, 'name' => 'Delivered',        'subtract_stock' => 1],
            ['order_status_id' => 10, 'language_id' => 1, 'name' => 'Completed',        'subtract_stock' => 0],
            ['order_status_id' => 11, 'language_id' => 1, 'name' => 'Awaiting Payment', 'subtract_stock' => 0],
        ]);
    }

    public function down(): void
    {
        // Intentionally not dropping catalog tables.
    }
};
