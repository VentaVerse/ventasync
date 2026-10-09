<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ch_shopee_stores', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 255)->nullable();
            $table->boolean('enabled')->default(1);
            $table->string('mode', 16)->default('sandbox');
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->text('partner_key')->nullable();
            $table->text('push_partner_key')->nullable();
            $table->boolean('apply_order_pushes')->default(0);
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->string('redirect_uri', 255)->nullable();
            $table->string('region', 16)->nullable();
            $table->unsignedBigInteger('sandbox_partner_id')->nullable();
            $table->text('sandbox_partner_key')->nullable();
            $table->text('sandbox_push_partner_key')->nullable();
            $table->unsignedBigInteger('sandbox_shop_id')->nullable();
            $table->text('sandbox_access_token')->nullable();
            $table->text('sandbox_refresh_token')->nullable();
            $table->timestamp('sandbox_expires_at')->nullable();
            $table->timestamp('sandbox_refresh_expires_at')->nullable();
            $table->string('sandbox_redirect_uri', 255)->nullable();
            $table->string('sandbox_region', 16)->nullable();
            $table->unsignedSmallInteger('sync_last_days')->nullable();
            $table->unsignedSmallInteger('sync_last_days_returns')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamp('last_return_sync_at')->nullable();
            $table->timestamp('last_review_sync_at')->nullable();
            $table->string('api_log_mode', 10)->default('all');
        });

        Schema::create('ch_shopee_api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
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
            $table->index(['api_path', 'created_at'], 'ch_shopee_api_logs_api_path_created_at_index');
            $table->index(['store_id'], 'ch_shopee_api_logs_store_id_index');
            $table->index(['user_id', 'created_at'], 'ch_shopee_api_logs_user_id_created_at_index');
        });

        Schema::create('ch_shopee_brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('brand_id');
            $table->string('name', 255);
            $table->tinyInteger('status')->default(1);
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['category_id', 'brand_id'], 'ch_shopee_brands_category_id_brand_id_unique');
            $table->index(['category_id'], 'ch_shopee_brands_category_id_index');
        });

        Schema::create('ch_shopee_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 255);
            $table->unsignedTinyInteger('level')->default(0);
            $table->boolean('leaf')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['category_id'], 'ch_shopee_categories_category_id_index');
            $table->index(['parent_id'], 'ch_shopee_categories_parent_id_index');
        });

        Schema::create('ch_shopee_category_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('region', 16)->default('');
            $table->json('attributes')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['category_id'], 'ch_shopee_category_templates_category_id_index');
            $table->unique(['category_id', 'region'], 'ch_shopee_category_templates_category_id_region_unique');
        });

        Schema::create('ch_shopee_item_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('shopee_item_id');
            $table->unsignedBigInteger('shopee_model_id')->nullable();
            $table->string('sku', 128);
            $table->string('item_name', 500)->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['shopee_item_id'], 'ch_shopee_item_cache_shopee_item_id_index');
            $table->index(['sku'], 'ch_shopee_item_cache_sku_index');
            $table->unique(['store_id', 'shopee_item_id', 'shopee_model_id'], 'ch_shopee_item_cache_store_id_item_id_model_id_unique');
        });

        Schema::create('ch_shopee_listing_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('product_id');
            $table->string('shopee_item_id', 64);
            $table->string('shopee_model_id', 64)->nullable();
            $table->string('sku', 128)->nullable();
            $table->string('live_status', 24)->nullable();
            $table->timestamp('live_checked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_action', 50)->nullable();
            $table->boolean('last_sync_ok')->nullable();
            $table->string('last_sync_error_code', 80)->nullable();
            $table->string('last_sync_error_message', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['live_status'], 'ch_shopee_listing_links_live_status_index');
            $table->index(['product_id'], 'ch_shopee_listing_links_product_id_index');
            $table->index(['shopee_item_id'], 'ch_shopee_listing_links_shopee_item_id_index');
            $table->index(['sku'], 'ch_shopee_listing_links_sku_index');
            $table->unique(['store_id', 'product_id', 'shopee_item_id', 'shopee_model_id'], 'ch_shopee_listing_links_unique');
        });

        Schema::create('ch_shopee_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('shopee_category_id')->nullable();
            $table->unsignedBigInteger('shopee_brand_id')->nullable();
            $table->json('logistic_ids')->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->decimal('sale_percent', 6, 2)->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->unsignedBigInteger('shopee_discount_id')->nullable();
            $table->string('item_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('attribute_values')->nullable();
            $table->json('image_order')->nullable();
            $table->decimal('weight', 8, 3)->nullable();
            $table->decimal('package_length', 10, 2)->nullable();
            $table->decimal('package_width', 10, 2)->nullable();
            $table->decimal('package_height', 10, 2)->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_source', 64)->nullable();
            $table->json('last_push_settings')->nullable();
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
            $table->timestamp('catalog_seen_at')->nullable();
            $table->string('video_path', 512)->nullable();
            $table->boolean('video_off')->default(0);
            $table->json('image_off')->nullable();
            $table->index(['product_id'], 'ch_shopee_listings_product_id_index');
            $table->unique(['store_id', 'product_id'], 'ch_shopee_listings_store_id_product_id_unique');
        });

        Schema::create('ch_shopee_logistics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('logistics_channel_id');
            $table->string('logistics_channel_name', 255);
            $table->boolean('cod_enabled')->default(0);
            $table->boolean('enabled')->default(0);
            $table->boolean('force_enable')->default(0);
            $table->string('fee_type', 50)->nullable();
            $table->json('weight_limit')->nullable();
            $table->json('item_max_dimension')->nullable();
            $table->json('volume_limit')->nullable();
            $table->unsignedBigInteger('mask_channel_id')->default(0);
            $table->text('logistics_description')->nullable();
            $table->boolean('support_pre_order')->default(0);
            $table->boolean('support_cross_border')->default(0);
            $table->json('raw_data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'logistics_channel_id'], 'ch_shopee_logistics_store_id_logistics_channel_id_unique');
        });

        Schema::create('ch_shopee_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopee_order_id');
            $table->string('item_id', 64)->nullable();
            $table->string('model_id', 64)->nullable();
            $table->string('sku', 128)->nullable();
            $table->string('name', 512)->nullable();
            $table->string('variation', 255)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('image', 512)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['shopee_order_id'], 'ch_shopee_order_products_shopee_order_id_index');
        });

        Schema::create('ch_shopee_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->string('shopee_status', 64);
            $table->string('context', 16)->default('order');
            $table->unsignedInteger('order_status_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['shopee_status', 'context'], 'ch_shopee_order_status_map_shopee_status_context_unique');
        });

        Schema::create('ch_shopee_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('region', 8);
            $table->string('order_sn', 64);
            $table->string('status', 64)->nullable();
            $table->timestamp('order_created_at')->nullable();
            $table->timestamp('order_updated_at')->nullable();
            $table->json('raw')->nullable();
            $table->json('buyer_invoice')->nullable();
            $table->json('fees')->nullable();
            $table->string('payout_status', 20)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('payout_amount', 12, 2)->nullable();
            $table->unsignedInteger('catalog_order_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['catalog_order_id'], 'ch_shopee_orders_catalog_order_id_index');
            $table->index(['order_created_at'], 'ch_shopee_orders_order_created_at_index');
            $table->index(['order_sn'], 'ch_shopee_orders_order_sn_index');
            $table->index(['order_updated_at'], 'ch_shopee_orders_order_updated_at_index');
            $table->index(['payout_status'], 'ch_shopee_orders_payout_status_index');
            $table->index(['region'], 'ch_shopee_orders_region_index');
            $table->index(['status'], 'ch_shopee_orders_status_index');
            $table->unique(['store_id', 'order_sn'], 'ch_shopee_orders_store_id_order_sn_unique');
        });

        Schema::create('ch_shopee_product_group_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopee_product_group_id');
            $table->string('attribute_key', 255);
            $table->text('value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['shopee_product_group_id'], 'ch_shopee_product_group_attributes_shopee_product_group_id_index');
        });

        Schema::create('ch_shopee_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopee_product_group_id');
            $table->unsignedInteger('product_id');
            $table->string('shopee_item_id', 64)->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_confirmed_at')->nullable();
            $table->text('push_error')->nullable();
            $table->index(['product_id'], 'ch_shopee_product_group_products_product_id_index');
            $table->unique(['shopee_product_group_id', 'product_id'], 'ch_shopee_product_group_products_unique');
        });

        Schema::create('ch_shopee_product_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('name', 255);
            $table->unsignedBigInteger('shopee_category_id')->nullable();
            $table->unsignedBigInteger('shopee_brand_id')->nullable();
            $table->json('logistic_ids')->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('watermark_template_id')->nullable();
            $table->index(['store_id'], 'ch_shopee_product_groups_store_id_index');
        });

        Schema::create('ch_shopee_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('region', 8);
            $table->string('return_sn', 64);
            $table->string('order_sn', 64);
            $table->unsignedBigInteger('shopee_order_id')->nullable();
            $table->string('status', 64)->nullable();
            $table->boolean('needs_logistics')->nullable();
            $table->tinyInteger('return_solution')->nullable();
            $table->string('reverse_logistics_status', 64)->nullable();
            $table->boolean('is_arrived_at_warehouse')->nullable();
            $table->string('seller_compensation_status', 64)->nullable();
            $table->string('reason', 255)->nullable();
            $table->text('reason_text')->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->json('items')->nullable();
            $table->json('negotiation')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('return_created_at')->nullable();
            $table->timestamp('return_updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['order_sn'], 'ch_shopee_returns_order_sn_index');
            $table->index(['region'], 'ch_shopee_returns_region_index');
            $table->index(['return_sn'], 'ch_shopee_returns_return_sn_index');
            $table->index(['shopee_order_id'], 'ch_shopee_returns_shopee_order_id_index');
            $table->index(['status'], 'ch_shopee_returns_status_index');
            $table->unique(['store_id', 'return_sn'], 'ch_shopee_returns_store_id_return_sn_unique');
        });

        Schema::table('ch_shopee_item_cache', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopee_item_cache_store_id_foreign')->references('id')->on('ch_shopee_stores')->onDelete('restrict');
        });

        Schema::table('ch_shopee_listing_links', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopee_listing_links_store_id_foreign')->references('id')->on('ch_shopee_stores')->onDelete('restrict');
        });

        Schema::table('ch_shopee_listings', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopee_listings_store_id_foreign')->references('id')->on('ch_shopee_stores')->onDelete('restrict');
        });

        Schema::table('ch_shopee_logistics', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopee_logistics_store_id_foreign')->references('id')->on('ch_shopee_stores')->onDelete('restrict');
        });

        Schema::table('ch_shopee_order_products', function (Blueprint $table) {
            $table->foreign('shopee_order_id', 'ch_shopee_order_products_shopee_order_id_foreign')->references('id')->on('ch_shopee_orders')->onDelete('cascade');
        });

        Schema::table('ch_shopee_orders', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopee_orders_store_id_foreign')->references('id')->on('ch_shopee_stores')->onDelete('restrict');
        });

        Schema::table('ch_shopee_product_group_attributes', function (Blueprint $table) {
            $table->foreign('shopee_product_group_id', 'ch_shopee_product_group_attributes_product_group_id_foreign')->references('id')->on('ch_shopee_product_groups')->onDelete('cascade');
        });

        Schema::table('ch_shopee_product_group_products', function (Blueprint $table) {
            $table->foreign('shopee_product_group_id', 'ch_shopee_product_group_products_shopee_product_group_id_foreign')->references('id')->on('ch_shopee_product_groups')->onDelete('cascade');
        });

        Schema::table('ch_shopee_product_groups', function (Blueprint $table) {
            $table->foreign('store_id', 'ch_shopee_product_groups_store_id_foreign')->references('id')->on('ch_shopee_stores')->onDelete('restrict');
        });

        Schema::table('ch_shopee_returns', function (Blueprint $table) {
            $table->foreign('shopee_order_id', 'ch_shopee_returns_shopee_order_id_foreign')->references('id')->on('ch_shopee_orders')->onDelete('set null');
            $table->foreign('store_id', 'ch_shopee_returns_store_id_foreign')->references('id')->on('ch_shopee_stores')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ch_shopee_returns');
        Schema::dropIfExists('ch_shopee_product_groups');
        Schema::dropIfExists('ch_shopee_product_group_products');
        Schema::dropIfExists('ch_shopee_product_group_attributes');
        Schema::dropIfExists('ch_shopee_orders');
        Schema::dropIfExists('ch_shopee_order_status_map');
        Schema::dropIfExists('ch_shopee_order_products');
        Schema::dropIfExists('ch_shopee_logistics');
        Schema::dropIfExists('ch_shopee_listings');
        Schema::dropIfExists('ch_shopee_listing_links');
        Schema::dropIfExists('ch_shopee_item_cache');
        Schema::dropIfExists('ch_shopee_category_templates');
        Schema::dropIfExists('ch_shopee_categories');
        Schema::dropIfExists('ch_shopee_brands');
        Schema::dropIfExists('ch_shopee_api_logs');
        Schema::dropIfExists('ch_shopee_stores');
        Schema::enableForeignKeyConstraints();
    }
};
