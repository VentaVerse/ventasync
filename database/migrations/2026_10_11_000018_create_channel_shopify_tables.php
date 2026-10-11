<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ch_shopify_stores', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 128);
            $table->string('shop_domain', 255);
            $table->text('access_token');
            $table->unsignedBigInteger('primary_location_id')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('country_code', 4)->nullable();
            $table->string('api_version', 16)->default('2024-10');
            $table->boolean('enabled')->default(1);
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedInteger('sync_last_days')->default(30);
            $table->date('sync_orders_from')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('api_log_mode', 10)->default('all');
            $table->unique(['shop_domain'], 'ch_shopify_stores_shop_domain_unique');
        });

        Schema::create('ch_shopify_api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('method', 8);
            $table->string('endpoint', 255);
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->unsignedInteger('response_time_ms')->default(0);
            $table->boolean('ok')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['store_id', 'created_at'], 'ch_shopify_api_logs_store_id_created_at_index');
        });

        Schema::create('ch_shopify_listing_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('shopify_product_id');
            $table->unsignedBigInteger('shopify_variant_id')->nullable();
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->string('sku', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['shopify_variant_id'], 'ch_shopify_listing_links_shopify_variant_id_index');
            $table->unique(['store_id', 'product_id'], 'ch_shopify_listing_links_store_id_product_id_unique');
        });

        Schema::create('ch_shopify_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_order_id_local');
            $table->unsignedBigInteger('erp_order_product_id')->nullable();
            $table->unsignedBigInteger('shopify_line_item_id')->nullable();
            $table->unsignedBigInteger('shopify_variant_id')->nullable();
            $table->unsignedBigInteger('shopify_product_id')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('variant_title', 255)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['erp_order_product_id'], 'ch_shopify_order_products_erp_order_product_id_index');
            $table->index(['sku'], 'ch_shopify_order_products_sku_index');
        });

        Schema::create('ch_shopify_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('shopify_status', 64);
            $table->string('context', 16)->default('order');
            $table->unsignedInteger('order_status_id')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'shopify_status', 'context'], 'ch_shopify_order_status_map_store_id_status_context_unique');
        });

        Schema::create('ch_shopify_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('shopify_order_id');
            $table->string('order_number', 64)->nullable();
            $table->string('financial_status', 32)->nullable();
            $table->string('fulfillment_status', 32)->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_shipping', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->string('customer_email', 191)->nullable();
            $table->string('customer_name', 191)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->unsignedBigInteger('erp_order_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['erp_order_id'], 'ch_shopify_orders_erp_order_id_index');
            $table->unique(['shopify_order_id'], 'ch_shopify_orders_shopify_order_id_unique');
        });

        Schema::create('ch_shopify_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('entity_type', 32);
            $table->string('direction', 8);
            $table->string('status', 16);
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['created_at'], 'ch_shopify_sync_logs_created_at_index');
            $table->index(['store_id', 'created_at'], 'ch_shopify_sync_logs_store_id_created_at_index');
        });

        Schema::table('ch_shopify_api_logs', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopify_api_logs_store_id_foreign')->references('id')->on('ch_shopify_stores')->onDelete('set null');
        });

        Schema::table('ch_shopify_listing_links', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopify_listing_links_store_id_foreign')->references('id')->on('ch_shopify_stores')->onDelete('cascade');
        });

        Schema::table('ch_shopify_order_products', function (Blueprint $table) {
            $table->foreign('shopify_order_id_local', 'ch_shopify_order_products_shopify_order_id_local_foreign')->references('id')->on('ch_shopify_orders')->onDelete('cascade');
        });

        Schema::table('ch_shopify_order_status_map', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopify_order_status_map_store_id_foreign')->references('id')->on('ch_shopify_stores')->onDelete('cascade');
        });

        Schema::table('ch_shopify_orders', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopify_orders_store_id_foreign')->references('id')->on('ch_shopify_stores')->onDelete('cascade');
        });

        Schema::table('ch_shopify_sync_logs', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopify_sync_logs_store_id_foreign')->references('id')->on('ch_shopify_stores')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ch_shopify_sync_logs');
        Schema::dropIfExists('ch_shopify_orders');
        Schema::dropIfExists('ch_shopify_order_status_map');
        Schema::dropIfExists('ch_shopify_order_products');
        Schema::dropIfExists('ch_shopify_listing_links');
        Schema::dropIfExists('ch_shopify_api_logs');
        Schema::dropIfExists('ch_shopify_stores');
        Schema::enableForeignKeyConstraints();
    }
};
