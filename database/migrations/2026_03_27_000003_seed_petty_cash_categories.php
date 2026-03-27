<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $categories = [
            ['name' => 'Gas/Fuel',          'sort_order' => 1, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Shipping/Courier',  'sort_order' => 2, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Tools & Supplies',  'sort_order' => 3, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Office Supplies',   'sort_order' => 4, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Food/Meals',        'sort_order' => 5, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Transportation',    'sort_order' => 6, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Miscellaneous',     'sort_order' => 7, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('petty_cash_categories')->insert($categories);
    }

    public function down(): void
    {
        DB::table('petty_cash_categories')->truncate();
    }
};
