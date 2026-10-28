<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ch_opencart_stores', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 128)->default('');
            $table->string('brand_color', 7)->nullable();
            $table->string('base_url', 255);
            $table->text('api_key');
            $table->boolean('enabled')->default(1);
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->date('sync_orders_from')->nullable();
            $table->unsignedSmallInteger('sync_last_days')->nullable();
            $table->timestamp('last_category_sync_at')->nullable();
            $table->timestamp('last_brand_sync_at')->nullable();
            $table->unsignedInteger('last_order_page')->default(0);
            $table->json('sync_log')->nullable();
            $table->boolean('review_auto_approve')->default(1);
            $table->timestamp('last_review_push_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('ch_opencart_listing_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('oc_product_id');
            $table->unsignedInteger('product_id');
            $table->string('sku', 64)->default('');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['product_id'], 'ch_opencart_listing_links_product_id_index');
            $table->index(['sku'], 'ch_opencart_listing_links_sku_index');
            $table->unique(['store_id', 'oc_product_id'], 'ch_opencart_listing_links_store_id_oc_product_id_unique');
        });

        Schema::create('ch_opencart_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('oc_status_id');
            $table->string('oc_status_name', 64)->default('');
            $table->unsignedInteger('order_status_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'oc_status_id'], 'ch_opencart_order_status_map_store_id_oc_status_id_unique');
        });

        Schema::create('ch_opencart_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('opencart_product_group_id');
            $table->unsignedInteger('product_id');
            $table->unique(['opencart_product_group_id', 'product_id'], 'ch_opencart_product_group_products_unique');
        });

        Schema::create('ch_opencart_product_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('name', 255);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('ch_opencart_sync_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('entity_type', 32);
            $table->string('direction', 8);
            $table->string('status', 16)->default('started');
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['created_at'], 'ch_opencart_sync_log_created_at_index');
        });

        Schema::table('ch_opencart_listing_links', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_opencart_listing_links_store_id_foreign')->references('id')->on('ch_opencart_stores')->onDelete('cascade');
        });

        Schema::table('ch_opencart_order_status_map', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_opencart_order_status_map_store_id_foreign')->references('id')->on('ch_opencart_stores')->onDelete('cascade');
        });

        Schema::table('ch_opencart_product_group_products', function (Blueprint $table) {
            $table->foreign('opencart_product_group_id', 'ch_opencart_product_group_products_product_group_id_foreign')->references('id')->on('ch_opencart_product_groups')->onDelete('cascade');
        });

        Schema::table('ch_opencart_product_groups', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_opencart_product_groups_store_id_foreign')->references('id')->on('ch_opencart_stores')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ch_opencart_sync_log');
        Schema::dropIfExists('ch_opencart_product_groups');
        Schema::dropIfExists('ch_opencart_product_group_products');
        Schema::dropIfExists('ch_opencart_order_status_map');
        Schema::dropIfExists('ch_opencart_listing_links');
        Schema::dropIfExists('ch_opencart_stores');
        Schema::enableForeignKeyConstraints();
    }
};
