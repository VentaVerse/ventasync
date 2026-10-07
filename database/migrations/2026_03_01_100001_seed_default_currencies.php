<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('currencies')->insert([
            ['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'exchange_rate' => 1.00000000, 'is_default' => true, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 56.50000000, 'is_default' => false, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'exchange_rate' => 7.80000000, 'is_default' => false, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('currencies')->whereIn('code', ['PHP', 'USD', 'CNY'])->delete();
    }
};
