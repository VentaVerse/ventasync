<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('lazada_product_group_attributes')) {
            return;
        }

        if (!Schema::hasColumn('lazada_product_group_attributes', 'lazada_profile_id')) {
            return;
        }

        Schema::table('lazada_product_group_attributes', function (Blueprint $table) {
            $table->dropForeign('lazada_profile_attributes_lazada_profile_id_foreign');
            $table->dropUnique('lazada_profile_attr_unique');
            $table->renameColumn('lazada_profile_id', 'lazada_product_group_id');
        });

        Schema::table('lazada_product_group_attributes', function (Blueprint $table) {
            $table->unique(
                ['lazada_product_group_id', 'attribute_key'],
                'lazada_product_group_attr_unique'
            );
            $table->foreign('lazada_product_group_id')
                ->references('id')
                ->on('lazada_product_groups')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('lazada_product_group_attributes')) {
            return;
        }

        if (!Schema::hasColumn('lazada_product_group_attributes', 'lazada_product_group_id')) {
            return;
        }

        Schema::table('lazada_product_group_attributes', function (Blueprint $table) {
            $table->dropForeign(['lazada_product_group_id']);
            $table->dropUnique('lazada_product_group_attr_unique');
            $table->renameColumn('lazada_product_group_id', 'lazada_profile_id');
        });

        Schema::table('lazada_product_group_attributes', function (Blueprint $table) {
            $table->unique(
                ['lazada_profile_id', 'attribute_key'],
                'lazada_profile_attr_unique'
            );
            $table->foreign('lazada_profile_id')
                ->references('id')
                ->on('lazada_product_groups')
                ->onDelete('cascade');
        });
    }
};
