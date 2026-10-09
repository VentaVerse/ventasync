<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ch_woocommerce_stores', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 128);
            $table->string('base_url', 255);
            $table->string('rest_style', 8)->default('pretty');
            $table->string('consumer_key', 128)->default('');
            $table->text('consumer_secret')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->json('webhook_ids')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->string('wc_version', 32)->nullable();
            $table->string('wp_version', 32)->nullable();
            $table->string('currency', 8)->nullable();
            $table->boolean('enabled')->default(0);
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedInteger('sync_last_days')->default(30);
            $table->date('sync_orders_from')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_category_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamp('last_review_push_at')->nullable();
            $table->string('api_log_mode', 16)->nullable();
            $table->string('sync_log_level', 16)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('ch_woocommerce_api_logs', function (Blueprint $table) {
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
            $table->index(['created_at'], 'ch_woocommerce_api_logs_created_at_index');
            $table->index(['store_id'], 'ch_woocommerce_api_logs_store_id_index');
        });

        Schema::create('ch_woocommerce_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('woo_category_id');
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->string('name', 255);
            $table->string('slug', 255)->default('');
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'woo_category_id'], 'ch_woocommerce_categories_store_id_woo_category_id_unique');
        });

        Schema::create('ch_woocommerce_listing_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variation_value_id')->nullable();
            $table->unsignedBigInteger('woo_product_id');
            $table->unsignedBigInteger('woo_variation_id')->nullable();
            $table->string('sku', 128)->default('');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['store_id', 'product_id'], 'ch_woocommerce_listing_links_store_id_product_id_index');
        });

        Schema::create('ch_woocommerce_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('woo_category_id')->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->decimal('sale_percent', 8, 2)->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('image_order')->nullable();
            $table->string('live_status', 16)->nullable();
            $table->timestamp('live_checked_at')->nullable();
            $table->decimal('live_price', 12, 2)->nullable();
            $table->integer('live_quantity')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_source', 16)->nullable();
            $table->json('last_push_settings')->nullable();
            $table->string('last_push_error', 480)->nullable();
            $table->timestamp('last_push_failed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('description_prefix_id')->nullable();
            $table->unsignedBigInteger('description_suffix_id')->nullable();
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
            $table->unique(['store_id', 'product_id'], 'ch_woocommerce_listings_store_id_product_id_unique');
        });

        Schema::create('ch_woocommerce_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woocommerce_order_id');
            $table->unsignedBigInteger('woo_line_id');
            $table->unsignedBigInteger('woo_product_id')->default(0);
            $table->unsignedBigInteger('woo_variation_id')->default(0);
            $table->string('name', 255)->default('');
            $table->string('sku', 128)->default('');
            $table->string('variant_label', 255)->default('');
            $table->integer('quantity')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('product_variation_value_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('ch_woocommerce_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('woo_status', 32);
            $table->unsignedInteger('order_status_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'woo_status'], 'ch_woocommerce_order_status_map_store_id_woo_status_unique');
        });

        Schema::create('ch_woocommerce_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('woo_order_id');
            $table->string('woo_order_number', 64)->default('');
            $table->string('status', 32)->default('');
            $table->string('currency', 8)->default('');
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('total_tax', 12, 2)->default(0);
            $table->string('payment_method', 64)->default('');
            $table->string('payment_method_title', 128)->default('');
            $table->string('shipping_method', 128)->default('');
            $table->string('customer_name', 191)->default('');
            $table->string('customer_email', 191)->default('');
            $table->string('customer_phone', 64)->default('');
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('shipping_lines')->nullable();
            $table->string('tracking_number', 190)->default('');
            $table->text('customer_note')->nullable();
            $table->timestamp('order_created_at')->nullable();
            $table->timestamp('order_updated_at')->nullable();
            $table->unsignedInteger('catalog_order_id')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'woo_order_id'], 'ch_woocommerce_orders_store_id_woo_order_id_unique');
        });

        Schema::create('ch_woocommerce_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woocommerce_product_group_id');
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('woo_product_id')->nullable();
            $table->string('sync_status', 16)->default('pending');
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_confirmed_at')->nullable();
            $table->string('push_error', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['woocommerce_product_group_id', 'product_id'], 'ch_woocommerce_product_group_products_unique');
        });

        Schema::create('ch_woocommerce_product_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('name', 128);
            $table->unsignedBigInteger('woo_category_id')->nullable();
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->decimal('markup_fixed', 12, 2)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('watermark_template_id')->nullable();
        });

        Schema::create('ch_woocommerce_sync_logs', function (Blueprint $table) {
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
        });

        Schema::table('ch_woocommerce_categories', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_woocommerce_categories_store_id_foreign')->references('id')->on('ch_woocommerce_stores')->onDelete('cascade');
        });

        Schema::table('ch_woocommerce_listing_links', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_woocommerce_listing_links_store_id_foreign')->references('id')->on('ch_woocommerce_stores')->onDelete('cascade');
        });

        Schema::table('ch_woocommerce_listings', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_woocommerce_listings_store_id_foreign')->references('id')->on('ch_woocommerce_stores')->onDelete('cascade');
        });

        Schema::table('ch_woocommerce_order_products', function (Blueprint $table) {
            $table->foreign('woocommerce_order_id', 'ch_woocommerce_order_products_woocommerce_order_id_foreign')->references('id')->on('ch_woocommerce_orders')->onDelete('cascade');
        });

        Schema::table('ch_woocommerce_order_status_map', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_woocommerce_order_status_map_store_id_foreign')->references('id')->on('ch_woocommerce_stores')->onDelete('cascade');
        });

        Schema::table('ch_woocommerce_orders', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_woocommerce_orders_store_id_foreign')->references('id')->on('ch_woocommerce_stores')->onDelete('cascade');
        });

        Schema::table('ch_woocommerce_product_group_products', function (Blueprint $table) {
            $table->foreign('woocommerce_product_group_id', 'ch_woocommerce_product_group_products_product_group_id_foreign')->references('id')->on('ch_woocommerce_product_groups')->onDelete('cascade');
        });

        Schema::table('ch_woocommerce_product_groups', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_woocommerce_product_groups_store_id_foreign')->references('id')->on('ch_woocommerce_stores')->onDelete('cascade');
        });

        Schema::table('ch_woocommerce_sync_logs', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_woocommerce_sync_logs_store_id_foreign')->references('id')->on('ch_woocommerce_stores')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ch_woocommerce_sync_logs');
        Schema::dropIfExists('ch_woocommerce_product_groups');
        Schema::dropIfExists('ch_woocommerce_product_group_products');
        Schema::dropIfExists('ch_woocommerce_orders');
        Schema::dropIfExists('ch_woocommerce_order_status_map');
        Schema::dropIfExists('ch_woocommerce_order_products');
        Schema::dropIfExists('ch_woocommerce_listings');
        Schema::dropIfExists('ch_woocommerce_listing_links');
        Schema::dropIfExists('ch_woocommerce_categories');
        Schema::dropIfExists('ch_woocommerce_api_logs');
        Schema::dropIfExists('ch_woocommerce_stores');
        Schema::enableForeignKeyConstraints();
    }
};
