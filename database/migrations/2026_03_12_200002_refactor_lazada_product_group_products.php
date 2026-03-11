<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_product_group_products', function (Blueprint $table) {
            // Rename old column
            $table->renameColumn('lazada_profile_id', 'lazada_product_group_id');
        });

        Schema::table('lazada_product_group_products', function (Blueprint $table) {
            // Add catalog product_id for direct product assignment
            $table->unsignedInteger('product_id')->nullable()->after('lazada_product_id');
            $table->string('sync_status', 20)->default('pending')->after('product_id');
            $table->timestamp('last_pushed_at')->nullable()->after('sync_status');
            $table->text('push_error')->nullable()->after('last_pushed_at');

            $table->index('product_id');
        });

        // Backfill product_id from lazada_products table
        DB::statement('
            UPDATE lazada_product_group_products AS gp
            JOIN lazada_products AS lp ON lp.id = gp.lazada_product_id
            SET gp.product_id = lp.product_id
            WHERE lp.product_id IS NOT NULL
        ');

        // Mark synced items (those with lazada_item_id) as pushed
        DB::statement('
            UPDATE lazada_product_group_products AS gp
            JOIN lazada_products AS lp ON lp.id = gp.lazada_product_id
            SET gp.sync_status = \'pushed\'
            WHERE lp.lazada_item_id IS NOT NULL AND lp.lazada_item_id != \'\'
        ');
    }

    public function down(): void
    {
        Schema::table('lazada_product_group_products', function (Blueprint $table) {
            $table->dropColumn(['product_id', 'sync_status', 'last_pushed_at', 'push_error']);
        });

        Schema::table('lazada_product_group_products', function (Blueprint $table) {
            $table->renameColumn('lazada_product_group_id', 'lazada_profile_id');
        });
    }
};
