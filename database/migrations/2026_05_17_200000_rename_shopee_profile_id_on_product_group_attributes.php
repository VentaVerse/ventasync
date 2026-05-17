<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shopee_product_group_attributes')) {
            return;
        }

        if (!Schema::hasColumn('shopee_product_group_attributes', 'shopee_profile_id')) {
            return;
        }

        Schema::table('shopee_product_group_attributes', function (Blueprint $table) {
            $table->dropForeign('shopee_profile_attributes_shopee_profile_id_foreign');
            $table->dropIndex('shopee_profile_attributes_shopee_profile_id_index');
            $table->renameColumn('shopee_profile_id', 'shopee_product_group_id');
        });

        Schema::table('shopee_product_group_attributes', function (Blueprint $table) {
            $table->index('shopee_product_group_id', 'shopee_product_group_attr_pg_idx');
            $table->foreign('shopee_product_group_id')
                ->references('id')
                ->on('shopee_product_groups')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shopee_product_group_attributes')) {
            return;
        }

        if (!Schema::hasColumn('shopee_product_group_attributes', 'shopee_product_group_id')) {
            return;
        }

        Schema::table('shopee_product_group_attributes', function (Blueprint $table) {
            $table->dropForeign(['shopee_product_group_id']);
            $table->dropIndex('shopee_product_group_attr_pg_idx');
            $table->renameColumn('shopee_product_group_id', 'shopee_profile_id');
        });

        Schema::table('shopee_product_group_attributes', function (Blueprint $table) {
            $table->index('shopee_profile_id', 'shopee_profile_attributes_shopee_profile_id_index');
            $table->foreign('shopee_profile_id')
                ->references('id')
                ->on('shopee_product_groups')
                ->onDelete('cascade');
        });
    }
};
