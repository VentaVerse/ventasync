<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shopee_product_group_products')
            ->where('sync_status', 'synced')
            ->update(['sync_status' => 'pushed']);
    }

    public function down(): void
    {
    }
};
