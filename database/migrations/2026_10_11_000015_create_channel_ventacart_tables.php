<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ch_ventacart_stores', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 128);
            $table->string('brand_color', 7)->nullable();
            $table->string('base_url', 255);
            $table->text('api_token');
            $table->boolean('enabled')->default(1);
            $table->string('sync_log_level', 16)->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedInteger('sync_last_days')->default(30);
            $table->date('sync_orders_from')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_category_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamp('last_review_push_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('api_log_mode', 10)->default('all');
        });

        Schema::create('ch_ventacart_api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('method', 10);
            $table->string('endpoint', 500);
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->unsignedInteger('response_time_ms')->default(0);
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->boolean('ok')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['created_at'], 'ch_ventacart_api_logs_created_at_index');
            $table->index(['store_id'], 'ch_ventacart_api_logs_store_id_index');
        });

        Schema::create('ch_ventacart_brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('ventacart_brand_id');
            $table->string('name', 255);
            $table->string('slug', 255)->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'ventacart_brand_id'], 'ch_ventacart_brands_store_id_ventacart_brand_id_unique');
        });

        Schema::create('ch_ventacart_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('ventacart_category_id');
            $table->string('name', 255);
            $table->string('slug', 255)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'ventacart_category_id'], 'ch_ventacart_categories_store_id_ventacart_category_id_unique');
        });

        Schema::create('ch_ventacart_listing_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('ventacart_product_id');
            $table->unsignedInteger('product_id');
            $table->string('sku', 100);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['sku'], 'ch_ventacart_listing_links_sku_index');
            $table->unique(['store_id', 'ventacart_product_id'], 'ch_ventacart_listing_links_store_id_ventacart_product_id_unique');
        });

        Schema::create('ch_ventacart_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('ventacart_category_id')->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('image_order')->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('live_status', 16)->nullable();
            $table->timestamp('live_checked_at')->nullable();
            $table->decimal('live_price', 12, 2)->nullable();
            $table->integer('live_quantity')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_source', 64)->nullable();
            $table->json('last_push_settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('description_prefix_id')->nullable();
            $table->unsignedBigInteger('description_suffix_id')->nullable();
            $table->string('last_push_error', 480)->nullable();
            $table->timestamp('last_push_failed_at')->nullable();
            $table->unsignedBigInteger('watermark_template_id')->nullable();
            $table->boolean('watermark_all_images')->default(0);
            $table->decimal('price', 15, 4)->nullable();
            $table->decimal('weight', 8, 3)->nullable();
            $table->decimal('package_length', 10, 2)->nullable();
            $table->decimal('package_width', 10, 2)->nullable();
            $table->decimal('package_height', 10, 2)->nullable();
            $table->timestamp('catalog_seen_at')->nullable();
            $table->string('video_path', 512)->nullable();
            $table->boolean('video_off')->default(0);
            $table->json('image_off')->nullable();
            $table->index(['live_status'], 'ch_ventacart_listings_live_status_index');
            $table->unique(['store_id', 'product_id'], 'ch_ventacart_listings_store_id_product_id_unique');
        });

        Schema::create('ch_ventacart_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ventacart_order_id');
            $table->string('sku', 100)->nullable();
            $table->string('name', 500)->nullable();
            $table->string('variant_label', 255)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['ventacart_order_id'], 'ch_ventacart_order_products_ventacart_order_id_index');
        });

        Schema::create('ch_ventacart_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('ventacart_status_id');
            $table->string('ventacart_status_name', 64)->default('');
            $table->unsignedInteger('order_status_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'ventacart_status_id'], 'ch_ventacart_order_status_map_store_id_status_id_unique');
        });

        Schema::create('ch_ventacart_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('ventacart_order_id');
            $table->unsignedBigInteger('ventacart_order_number')->nullable();
            $table->string('status', 50)->nullable();
            $table->unsignedInteger('status_id')->nullable();
            $table->string('customer_name', 200)->nullable();
            $table->string('customer_email', 200)->nullable();
            $table->string('customer_group', 64)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('payment_method', 64)->nullable();
            $table->string('shipping_method', 64)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('courier_provider', 32)->nullable();
            $table->string('courier_name', 120)->nullable();
            $table->string('courier_tracking_number', 100)->nullable();
            $table->string('courier_status', 50)->nullable();
            $table->timestamp('courier_booked_at')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('raw')->nullable();
            $table->unsignedBigInteger('catalog_order_id')->nullable();
            $table->timestamp('order_created_at')->nullable();
            $table->timestamp('order_updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['catalog_order_id'], 'ch_ventacart_orders_catalog_order_id_index');
            $table->index(['order_created_at'], 'ch_ventacart_orders_order_created_at_index');
            $table->index(['status'], 'ch_ventacart_orders_status_index');
            $table->index(['store_id'], 'ch_ventacart_orders_store_id_index');
            $table->unique(['store_id', 'ventacart_order_id'], 'ch_ventacart_orders_store_id_ventacart_order_id_unique');
            $table->index(['ventacart_order_id'], 'ch_ventacart_orders_ventacart_order_id_index');
        });

        Schema::create('ch_ventacart_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ventacart_product_group_id');
            $table->unsignedInteger('product_id');
            $table->string('ventacart_sku', 100)->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_confirmed_at')->nullable();
            $table->text('push_error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['ventacart_product_group_id', 'product_id'], 'ch_ventacart_product_group_products_unique');
        });

        Schema::create('ch_ventacart_product_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('name', 255);
            $table->unsignedBigInteger('ventacart_category_id')->nullable();
            $table->unsignedBigInteger('ventacart_brand_id')->nullable();
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->decimal('markup_fixed', 12, 2)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('watermark_template_id')->nullable();
        });

        Schema::create('ch_ventacart_status_placements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('ventacart_status_id');
            $table->string('ventacart_status_name', 64)->default('');
            $table->string('placement', 32)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'ventacart_status_id'], 'ch_ventacart_status_placements_store_id_status_id_unique');
        });

        Schema::create('ch_ventacart_sync_logs', function (Blueprint $table) {
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
            $table->index(['created_at'], 'ch_ventacart_sync_logs_created_at_index');
        });

        Schema::table('ch_ventacart_brands', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_ventacart_brands_store_id_foreign')->references('id')->on('ch_ventacart_stores')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_categories', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_ventacart_categories_store_id_foreign')->references('id')->on('ch_ventacart_stores')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_listing_links', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_ventacart_listing_links_store_id_foreign')->references('id')->on('ch_ventacart_stores')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_listings', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_ventacart_listings_store_id_foreign')->references('id')->on('ch_ventacart_stores')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_order_products', function (Blueprint $table) {
            $table->foreign('ventacart_order_id', 'ch_ventacart_order_products_ventacart_order_id_foreign')->references('id')->on('ch_ventacart_orders')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_order_status_map', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_ventacart_order_status_map_store_id_foreign')->references('id')->on('ch_ventacart_stores')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_orders', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_ventacart_orders_store_id_foreign')->references('id')->on('ch_ventacart_stores')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_product_group_products', function (Blueprint $table) {
            $table->foreign('ventacart_product_group_id', 'ch_ventacart_product_group_products_product_group_id_foreign')->references('id')->on('ch_ventacart_product_groups')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_product_groups', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_ventacart_product_groups_store_id_foreign')->references('id')->on('ch_ventacart_stores')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_status_placements', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_ventacart_status_placements_store_id_foreign')->references('id')->on('ch_ventacart_stores')->onDelete('cascade');
        });

        Schema::table('ch_ventacart_sync_logs', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_ventacart_sync_logs_store_id_foreign')->references('id')->on('ch_ventacart_stores')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ch_ventacart_sync_logs');
        Schema::dropIfExists('ch_ventacart_status_placements');
        Schema::dropIfExists('ch_ventacart_product_groups');
        Schema::dropIfExists('ch_ventacart_product_group_products');
        Schema::dropIfExists('ch_ventacart_orders');
        Schema::dropIfExists('ch_ventacart_order_status_map');
        Schema::dropIfExists('ch_ventacart_order_products');
        Schema::dropIfExists('ch_ventacart_listings');
        Schema::dropIfExists('ch_ventacart_listing_links');
        Schema::dropIfExists('ch_ventacart_categories');
        Schema::dropIfExists('ch_ventacart_brands');
        Schema::dropIfExists('ch_ventacart_api_logs');
        Schema::dropIfExists('ch_ventacart_stores');
        Schema::enableForeignKeyConstraints();
    }
};
