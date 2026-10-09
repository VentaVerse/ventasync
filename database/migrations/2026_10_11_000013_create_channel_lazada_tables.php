<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ch_lazada_stores', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 255)->nullable();
            $table->boolean('enabled')->default(1);
            $table->string('mode', 16)->default('sandbox');
            $table->string('region', 16)->nullable();
            $table->string('app_key', 64)->nullable();
            $table->text('app_secret')->nullable();
            $table->string('redirect_uri', 255)->nullable();
            $table->text('auth_code')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->string('account', 64)->nullable();
            $table->string('country', 8)->nullable();
            $table->unsignedSmallInteger('sync_last_days')->nullable();
            $table->unsignedSmallInteger('sync_last_days_returns')->nullable();
            $table->string('sandbox_app_key', 64)->nullable();
            $table->text('sandbox_app_secret')->nullable();
            $table->string('sandbox_redirect_uri', 255)->nullable();
            $table->text('sandbox_auth_code')->nullable();
            $table->text('sandbox_access_token')->nullable();
            $table->text('sandbox_refresh_token')->nullable();
            $table->dateTime('sandbox_expires_at')->nullable();
            $table->dateTime('sandbox_refresh_expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamp('last_return_sync_at')->nullable();
            $table->timestamp('last_review_sync_at')->nullable();
            $table->string('api_log_mode', 10)->default('all');
        });

        Schema::create('ch_lazada_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('pack', 64)->nullable();
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
            $table->index(['api_path', 'created_at'], 'ch_lazada_api_logs_api_path_created_at_index');
            $table->index(['pack', 'created_at'], 'ch_lazada_api_logs_pack_created_at_index');
            $table->index(['user_id', 'created_at'], 'ch_lazada_api_logs_user_id_created_at_index');
        });

        Schema::create('ch_lazada_brands', function (Blueprint $table) {
            $table->id();
            $table->string('region', 8);
            $table->unsignedBigInteger('brand_id');
            $table->string('name', 255);
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['brand_id'], 'ch_lazada_brands_brand_id_index');
            $table->index(['name'], 'ch_lazada_brands_name_index');
            $table->unique(['region', 'brand_id'], 'ch_lazada_brands_region_brand_id_unique');
            $table->index(['region'], 'ch_lazada_brands_region_index');
        });

        Schema::create('ch_lazada_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('name', 255);
            $table->boolean('leaf')->default(0);
            $table->boolean('var')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('level')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['category_id'], 'ch_lazada_categories_category_id_index');
            $table->unique(['category_id'], 'ch_lazada_categories_category_id_unique');
            $table->index(['leaf'], 'ch_lazada_categories_leaf_index');
            $table->index(['level'], 'ch_lazada_categories_level_index');
            $table->index(['name'], 'ch_lazada_categories_name_index');
            $table->index(['parent_id'], 'ch_lazada_categories_parent_id_index');
        });

        Schema::create('ch_lazada_category_templates', function (Blueprint $table) {
            $table->id();
            $table->string('region', 10)->nullable();
            $table->unsignedBigInteger('primary_category_id');
            $table->json('template_body')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['region', 'primary_category_id'], 'ch_lazada_category_templates_region_primary_category_id_unique');
        });

        Schema::create('ch_lazada_image_links', function (Blueprint $table) {
            $table->id();
            $table->string('region', 8);
            $table->text('original_url');
            $table->string('original_hash', 64);
            $table->text('lazada_url');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['original_hash'], 'ch_lazada_image_links_original_hash_index');
            $table->index(['region'], 'ch_lazada_image_links_region_index');
            $table->unique(['region', 'original_hash'], 'ch_lazada_image_links_region_original_hash_unique');
        });

        Schema::create('ch_lazada_listing_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_listing_id');
            $table->string('attribute_key', 191);
            $table->text('value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['lazada_listing_id', 'attribute_key'], 'ch_lazada_listing_attributes_listing_id_attribute_key_unique');
        });

        Schema::create('ch_lazada_listing_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_listing_id');
            $table->unsignedInteger('product_variation_value_id')->nullable();
            $table->string('seller_sku', 64)->nullable();
            $table->string('shop_sku', 191)->nullable();
            $table->unsignedBigInteger('sku_id')->nullable();
            $table->decimal('price', 15, 4)->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['lazada_listing_id', 'product_variation_value_id'], 'ch_lazada_listing_variations_unique');
        });

        Schema::create('ch_lazada_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('image_order')->nullable();
            $table->unsignedBigInteger('primary_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('brand_name_override', 255)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('sale_percent', 6, 2)->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->timestamp('sale_pushed_at')->nullable();
            $table->decimal('total_price', 12, 2)->nullable();
            $table->string('lazada_item_id', 255)->nullable();
            $table->string('live_status', 24)->nullable();
            $table->timestamp('live_checked_at')->nullable();
            $table->timestamp('lazada_deleted_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->string('status', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_action', 50)->nullable();
            $table->boolean('last_sync_ok')->nullable();
            $table->string('last_sync_error_code', 80)->nullable();
            $table->string('last_sync_error_message', 255)->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_source', 120)->nullable();
            $table->string('last_push_error', 480)->nullable();
            $table->timestamp('last_push_failed_at')->nullable();
            $table->json('last_push_settings')->nullable();
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
            $table->index(['live_status'], 'ch_lazada_listings_live_status_index');
            $table->index(['primary_category_id'], 'ch_lazada_listings_primary_category_id_index');
            $table->index(['product_id'], 'ch_lazada_listings_product_id_index');
            $table->index(['store_id'], 'ch_lazada_listings_store_id_index');
        });

        Schema::create('ch_lazada_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_order_id');
            $table->string('order_item_id', 64);
            $table->string('sku', 128)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('variation', 255)->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('item_price', 12, 2)->nullable();
            $table->decimal('paid_price', 12, 2)->nullable();
            $table->string('image', 512)->nullable();
            $table->string('status', 64)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['lazada_order_id'], 'ch_lazada_order_products_lazada_order_id_index');
            $table->unique(['lazada_order_id', 'order_item_id'], 'ch_lazada_order_products_lazada_order_id_order_item_id_unique');
            $table->index(['order_item_id'], 'ch_lazada_order_products_order_item_id_index');
            $table->index(['status'], 'ch_lazada_order_products_status_index');
        });

        Schema::create('ch_lazada_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->string('lazada_status', 64);
            $table->string('context', 16)->default('order');
            $table->unsignedInteger('order_status_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['lazada_status', 'context'], 'ch_lazada_order_status_map_lazada_status_context_unique');
        });

        Schema::create('ch_lazada_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('region', 8);
            $table->string('order_id', 64);
            $table->string('status', 64)->nullable();
            $table->timestamp('order_created_at')->nullable();
            $table->timestamp('order_updated_at')->nullable();
            $table->json('raw')->nullable();
            $table->json('fees')->nullable();
            $table->string('payout_status', 20)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedInteger('catalog_order_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['catalog_order_id'], 'ch_lazada_orders_catalog_order_id_index');
            $table->index(['order_created_at'], 'ch_lazada_orders_order_created_at_index');
            $table->index(['order_id'], 'ch_lazada_orders_order_id_index');
            $table->index(['order_updated_at'], 'ch_lazada_orders_order_updated_at_index');
            $table->index(['payout_status'], 'ch_lazada_orders_payout_status_index');
            $table->index(['region'], 'ch_lazada_orders_region_index');
            $table->index(['status'], 'ch_lazada_orders_status_index');
            $table->index(['store_id'], 'ch_lazada_orders_store_id_index');
            $table->unique(['store_id', 'order_id'], 'ch_lazada_orders_store_id_order_id_unique');
        });

        Schema::create('ch_lazada_product_group_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_product_group_id');
            $table->string('attribute_key', 191);
            $table->text('value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['lazada_product_group_id', 'attribute_key'], 'ch_lazada_product_group_attributes_unique');
        });

        Schema::create('ch_lazada_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_listing_id');
            $table->unsignedInteger('product_id')->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('last_pushed_at')->nullable();
            $table->text('push_error')->nullable();
            $table->unsignedBigInteger('lazada_product_group_id');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id'], 'ch_lazada_product_group_products_product_id_index');
            $table->unique(['lazada_listing_id', 'lazada_product_group_id'], 'ch_lazada_product_group_products_unique');
        });

        Schema::create('ch_lazada_product_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('name', 255);
            $table->unsignedBigInteger('lazada_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('brand_name_override', 255)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('watermark_template_id')->nullable();
            $table->index(['store_id'], 'ch_lazada_product_groups_store_id_index');
        });

        Schema::create('ch_lazada_reverse_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('region', 10);
            $table->string('reverse_order_id', 255);
            $table->string('trade_order_id', 255)->nullable();
            $table->string('reverse_status', 255)->nullable();
            $table->string('reverse_type', 255)->nullable();
            $table->string('reason', 255)->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->timestamp('return_created_at')->nullable();
            $table->json('items')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['region'], 'ch_lazada_reverse_orders_region_index');
            $table->index(['region', 'return_created_at'], 'ch_lazada_reverse_orders_region_return_created_at_index');
            $table->index(['reverse_order_id'], 'ch_lazada_reverse_orders_reverse_order_id_index');
            $table->index(['reverse_status'], 'ch_lazada_reverse_orders_reverse_status_index');
            $table->index(['store_id'], 'ch_lazada_reverse_orders_store_id_index');
            $table->unique(['store_id', 'reverse_order_id'], 'ch_lazada_reverse_orders_store_id_reverse_order_id_unique');
            $table->index(['trade_order_id'], 'ch_lazada_reverse_orders_trade_order_id_index');
        });

        Schema::table('ch_lazada_listing_attributes', function (Blueprint $table) {
            $table->foreign('lazada_listing_id', 'ch_lazada_listing_attributes_lazada_listing_id_foreign')->references('id')->on('ch_lazada_listings')->onDelete('cascade');
        });

        Schema::table('ch_lazada_listing_variations', function (Blueprint $table) {
            $table->foreign('lazada_listing_id', 'ch_lazada_listing_variations_lazada_listing_id_foreign')->references('id')->on('ch_lazada_listings')->onDelete('cascade');
        });

        Schema::table('ch_lazada_order_products', function (Blueprint $table) {
            $table->foreign('lazada_order_id', 'ch_lazada_order_products_lazada_order_id_foreign')->references('id')->on('ch_lazada_orders')->onDelete('cascade');
        });

        Schema::table('ch_lazada_product_group_attributes', function (Blueprint $table) {
            $table->foreign('lazada_product_group_id', 'ch_lazada_product_group_attributes_product_group_id_foreign')->references('id')->on('ch_lazada_product_groups')->onDelete('cascade');
        });

        Schema::table('ch_lazada_product_group_products', function (Blueprint $table) {
            $table->foreign('lazada_listing_id', 'ch_lazada_product_group_products_lazada_listing_id_foreign')->references('id')->on('ch_lazada_listings')->onDelete('cascade');
            $table->foreign('lazada_product_group_id', 'ch_lazada_product_group_products_lazada_product_group_id_foreign')->references('id')->on('ch_lazada_product_groups')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ch_lazada_reverse_orders');
        Schema::dropIfExists('ch_lazada_product_groups');
        Schema::dropIfExists('ch_lazada_product_group_products');
        Schema::dropIfExists('ch_lazada_product_group_attributes');
        Schema::dropIfExists('ch_lazada_orders');
        Schema::dropIfExists('ch_lazada_order_status_map');
        Schema::dropIfExists('ch_lazada_order_products');
        Schema::dropIfExists('ch_lazada_listings');
        Schema::dropIfExists('ch_lazada_listing_variations');
        Schema::dropIfExists('ch_lazada_listing_attributes');
        Schema::dropIfExists('ch_lazada_image_links');
        Schema::dropIfExists('ch_lazada_category_templates');
        Schema::dropIfExists('ch_lazada_categories');
        Schema::dropIfExists('ch_lazada_brands');
        Schema::dropIfExists('ch_lazada_api_logs');
        Schema::dropIfExists('ch_lazada_stores');
        Schema::enableForeignKeyConstraints();
    }
};
