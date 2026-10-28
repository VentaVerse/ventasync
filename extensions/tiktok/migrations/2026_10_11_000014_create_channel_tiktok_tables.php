<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ch_tiktok_stores', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 128)->nullable();
            $table->boolean('enabled')->default(1);
            $table->string('mode', 16)->default('sandbox');
            $table->string('app_key', 64)->nullable();
            $table->text('app_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('refresh_expires_at')->nullable();
            $table->string('shop_id', 64)->nullable();
            $table->string('shop_cipher', 255)->nullable();
            $table->string('shop_code', 64)->nullable();
            $table->string('shop_name', 255)->nullable();
            $table->string('warehouse_id', 64)->nullable();
            $table->string('redirect_uri', 255)->nullable();
            $table->string('region', 16)->nullable();
            $table->unsignedSmallInteger('sync_last_days')->default(15);
            $table->unsignedSmallInteger('sync_last_days_returns')->nullable();
            $table->json('order_tab_map')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->string('sandbox_app_key', 64)->nullable();
            $table->text('sandbox_app_secret')->nullable();
            $table->text('sandbox_access_token')->nullable();
            $table->text('sandbox_refresh_token')->nullable();
            $table->dateTime('sandbox_expires_at')->nullable();
            $table->dateTime('sandbox_refresh_expires_at')->nullable();
            $table->string('sandbox_shop_id', 64)->nullable();
            $table->string('sandbox_shop_cipher', 255)->nullable();
            $table->string('sandbox_shop_code', 64)->nullable();
            $table->string('sandbox_shop_name', 255)->nullable();
            $table->string('sandbox_warehouse_id', 64)->nullable();
            $table->string('sandbox_redirect_uri', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('api_log_mode', 10)->default('all');
        });

        Schema::create('ch_tiktok_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('pack', 128)->nullable();
            $table->string('method', 8);
            $table->string('api_path', 255);
            $table->boolean('auth_required')->default(0);
            $table->json('request_params')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->boolean('ok')->default(0);
            $table->json('response_body')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['api_path', 'created_at'], 'ch_tiktok_api_logs_api_path_created_at_index');
            $table->index(['user_id', 'created_at'], 'ch_tiktok_api_logs_user_id_created_at_index');
        });

        Schema::create('ch_tiktok_categories', function (Blueprint $table) {
            $table->string('id', 255);
            $table->string('parent_id', 255)->nullable();
            $table->string('name', 255);
            $table->boolean('is_leaf')->default(0);
            $table->json('permission_statuses')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->primary(['id']);
        });

        Schema::create('ch_tiktok_category_templates', function (Blueprint $table) {
            $table->id();
            $table->string('category_id', 64);
            $table->json('attributes')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['category_id'], 'ch_tiktok_category_templates_category_id_unique');
        });

        Schema::create('ch_tiktok_description_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->default(0);
            $table->char('content_hash', 64);
            $table->string('url', 1024);
            $table->string('uri', 512)->nullable();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'content_hash'], 'ch_tiktok_description_images_store_id_content_hash_unique');
        });

        Schema::create('ch_tiktok_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->string('tiktok_product_id', 64)->nullable();
            $table->text('tiktok_sku_id')->nullable();
            $table->string('live_status', 32)->nullable();
            $table->timestamp('live_checked_at')->nullable();
            $table->string('tiktok_category_id', 64)->nullable();
            $table->string('brand_id', 64)->nullable();
            $table->string('brand_name', 191)->nullable();
            $table->json('attribute_values')->nullable();
            $table->json('image_order')->nullable();
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->decimal('sale_percent', 5, 2)->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->string('tiktok_activity_id', 64)->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_source', 191)->nullable();
            $table->string('last_push_error', 480)->nullable();
            $table->timestamp('last_push_failed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
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
            $table->index(['live_status'], 'ch_tiktok_listings_live_status_index');
            $table->index(['store_id'], 'ch_tiktok_listings_store_id_index');
            $table->unique(['store_id', 'product_id'], 'ch_tiktok_listings_store_id_product_id_unique');
            $table->index(['tiktok_product_id'], 'ch_tiktok_listings_tiktok_product_id_index');
        });

        Schema::create('ch_tiktok_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tiktok_order_id');
            $table->string('order_line_item_id', 64)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('name', 500)->nullable();
            $table->string('variation', 255)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('item_price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->string('status', 50)->nullable();
            $table->string('image', 1000)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['tiktok_order_id'], 'ch_tiktok_order_products_tiktok_order_id_index');
        });

        Schema::create('ch_tiktok_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->string('tiktok_status', 64);
            $table->string('context', 16)->default('order');
            $table->unsignedInteger('order_status_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['tiktok_status', 'context'], 'ch_tiktok_order_status_map_tiktok_status_context_unique');
        });

        Schema::create('ch_tiktok_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('region', 10)->default('PH');
            $table->string('order_id', 64);
            $table->string('status', 50)->nullable();
            $table->timestamp('order_created_at')->nullable();
            $table->timestamp('order_updated_at')->nullable();
            $table->json('raw')->nullable();
            $table->json('fees')->nullable();
            $table->string('buyer_name', 200)->nullable();
            $table->string('payout_status', 30)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('catalog_order_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['catalog_order_id'], 'ch_tiktok_orders_catalog_order_id_index');
            $table->index(['order_created_at'], 'ch_tiktok_orders_order_created_at_index');
            $table->index(['order_id'], 'ch_tiktok_orders_order_id_index');
            $table->index(['region'], 'ch_tiktok_orders_region_index');
            $table->index(['status'], 'ch_tiktok_orders_status_index');
            $table->index(['store_id'], 'ch_tiktok_orders_store_id_index');
            $table->unique(['store_id', 'order_id'], 'ch_tiktok_orders_store_id_order_id_unique');
        });

        Schema::create('ch_tiktok_product_group_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tiktok_product_group_id');
            $table->string('attribute_key', 64);
            $table->text('value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['tiktok_product_group_id', 'attribute_key'], 'ch_tiktok_product_group_attributes_unique');
        });

        Schema::create('ch_tiktok_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tiktok_product_group_id');
            $table->unsignedBigInteger('product_id');
            $table->string('tiktok_product_id', 255)->nullable();
            $table->text('tiktok_sku_id')->nullable();
            $table->string('sync_status', 255)->default('pending');
            $table->timestamp('last_pushed_at')->nullable();
            $table->text('push_error')->nullable();
            $table->index(['product_id'], 'ch_tiktok_product_group_products_product_id_index');
            $table->unique(['tiktok_product_group_id', 'product_id'], 'ch_tiktok_product_group_products_unique');
        });

        Schema::create('ch_tiktok_product_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('name', 255);
            $table->string('tiktok_category_id', 255);
            $table->decimal('markup_percent', 10, 2)->nullable();
            $table->decimal('markup_fixed', 10, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('watermark_template_id')->nullable();
            $table->index(['store_id'], 'ch_tiktok_product_groups_store_id_index');
        });

        Schema::create('ch_tiktok_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('return_id', 64);
            $table->string('order_id', 64)->nullable();
            $table->string('return_type', 64)->nullable();
            $table->string('return_status', 64)->nullable();
            $table->string('reason', 255)->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->timestamp('return_created_at')->nullable();
            $table->timestamp('return_updated_at')->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->json('items')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['order_id'], 'ch_tiktok_returns_order_id_index');
            $table->index(['return_status'], 'ch_tiktok_returns_return_status_index');
            $table->index(['store_id'], 'ch_tiktok_returns_store_id_index');
            $table->unique(['store_id', 'return_id'], 'ch_tiktok_returns_store_id_return_id_unique');
        });

        Schema::table('ch_tiktok_order_products', function (Blueprint $table) {
            $table->foreign('tiktok_order_id', 'ch_tiktok_order_products_tiktok_order_id_foreign')->references('id')->on('ch_tiktok_orders')->onDelete('cascade');
        });

        Schema::table('ch_tiktok_product_group_attributes', function (Blueprint $table) {
            $table->foreign('tiktok_product_group_id', 'ch_tiktok_product_group_attributes_product_group_id_foreign')->references('id')->on('ch_tiktok_product_groups')->onDelete('cascade');
        });

        Schema::table('ch_tiktok_product_group_products', function (Blueprint $table) {
            $table->foreign('tiktok_product_group_id', 'ch_tiktok_product_group_products_tiktok_product_group_id_foreign')->references('id')->on('ch_tiktok_product_groups')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ch_tiktok_returns');
        Schema::dropIfExists('ch_tiktok_product_groups');
        Schema::dropIfExists('ch_tiktok_product_group_products');
        Schema::dropIfExists('ch_tiktok_product_group_attributes');
        Schema::dropIfExists('ch_tiktok_orders');
        Schema::dropIfExists('ch_tiktok_order_status_map');
        Schema::dropIfExists('ch_tiktok_order_products');
        Schema::dropIfExists('ch_tiktok_listings');
        Schema::dropIfExists('ch_tiktok_description_images');
        Schema::dropIfExists('ch_tiktok_category_templates');
        Schema::dropIfExists('ch_tiktok_categories');
        Schema::dropIfExists('ch_tiktok_api_logs');
        Schema::dropIfExists('ch_tiktok_stores');
        Schema::enableForeignKeyConstraints();
    }
};
