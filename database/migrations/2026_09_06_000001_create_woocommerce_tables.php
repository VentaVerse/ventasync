<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_settings', function (Blueprint $t) {
            $t->id();
            $t->string('store_name', 128);
            $t->string('base_url', 255);
            $t->string('rest_style', 8)->default('pretty');
            $t->string('consumer_key', 128)->default('');
            $t->text('consumer_secret')->nullable();
            $t->text('webhook_secret')->nullable();
            $t->json('webhook_ids')->nullable();
            $t->timestamp('connected_at')->nullable();
            $t->string('wc_version', 32)->nullable();
            $t->string('wp_version', 32)->nullable();
            $t->string('currency', 8)->nullable();
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('warehouse_id')->nullable();
            $t->unsignedInteger('sync_last_days')->default(30);
            $t->date('sync_orders_from')->nullable();
            $t->timestamp('last_order_sync_at')->nullable();
            $t->timestamp('last_category_sync_at')->nullable();
            $t->timestamp('last_stock_push_at')->nullable();
            $t->timestamp('last_review_push_at')->nullable();
            $t->string('api_log_mode', 16)->nullable();
            $t->string('sync_log_level', 16)->nullable();
            $t->timestamps();
        });

        Schema::create('woocommerce_categories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_setting_id');
            $t->unsignedBigInteger('woo_category_id');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->string('name', 255);
            $t->string('slug', 255)->default('');
            $t->unsignedInteger('count')->default(0);
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['woocommerce_setting_id', 'woo_category_id'], 'woo_categories_store_cat_unique');
            $t->foreign('woocommerce_setting_id', 'woo_cat_store_fk')->references('id')->on('woocommerce_settings')->cascadeOnDelete();
        });

        Schema::create('woocommerce_product_groups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_setting_id');
            $t->string('name', 128);
            $t->unsignedBigInteger('woo_category_id')->nullable();
            $t->decimal('markup_percent', 8, 2)->default(0);
            $t->decimal('markup_fixed', 12, 2)->default(0);
            $t->timestamps();
            $t->foreign('woocommerce_setting_id', 'woo_pg_store_fk')->references('id')->on('woocommerce_settings')->cascadeOnDelete();
        });

        Schema::create('woocommerce_product_group_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_product_group_id');
            $t->unsignedInteger('product_id');
            $t->unsignedBigInteger('woo_product_id')->nullable();
            $t->string('sync_status', 16)->default('pending');
            $t->timestamp('last_pushed_at')->nullable();
            $t->timestamp('last_confirmed_at')->nullable();
            $t->string('push_error', 500)->nullable();
            $t->timestamps();
            $t->unique(['woocommerce_product_group_id', 'product_id'], 'woo_pgp_unique');
            $t->foreign('woocommerce_product_group_id', 'woo_pgp_parent_fk')->references('id')->on('woocommerce_product_groups')->cascadeOnDelete();
        });

        Schema::create('woocommerce_product_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_setting_id');
            $t->unsignedInteger('product_id');
            $t->unsignedInteger('product_option_value_id')->nullable();
            $t->unsignedBigInteger('woo_product_id');
            $t->unsignedBigInteger('woo_variation_id')->nullable();
            $t->string('sku', 128)->default('');
            $t->timestamps();
            $t->index(['woocommerce_setting_id', 'product_id'], 'woo_links_store_product');
            $t->foreign('woocommerce_setting_id', 'woo_link_store_fk')->references('id')->on('woocommerce_settings')->cascadeOnDelete();
        });

        Schema::create('woocommerce_listings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_setting_id');
            $t->unsignedInteger('product_id');
            $t->decimal('markup_percent', 8, 2)->nullable();
            $t->decimal('markup_fixed', 12, 2)->nullable();
            $t->string('name', 255)->nullable();
            $t->text('description')->nullable();
            $t->string('live_status', 16)->nullable();
            $t->timestamp('live_checked_at')->nullable();
            $t->decimal('live_price', 12, 2)->nullable();
            $t->integer('live_quantity')->nullable();
            $t->timestamp('last_pushed_at')->nullable();
            $t->string('last_push_source', 16)->nullable();
            $t->json('last_push_settings')->nullable();
            $t->string('last_push_error', 480)->nullable();
            $t->timestamp('last_push_failed_at')->nullable();
            $t->timestamps();
            $t->unique(['woocommerce_setting_id', 'product_id'], 'woo_listing_store_product_unique');
            $t->foreign('woocommerce_setting_id', 'woo_listing_store_fk')->references('id')->on('woocommerce_settings')->cascadeOnDelete();
        });

        Schema::create('woocommerce_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_setting_id');
            $t->unsignedBigInteger('woo_order_id');
            $t->string('woo_order_number', 64)->default('');
            $t->string('status', 32)->default('');
            $t->string('currency', 8)->default('');
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('shipping_total', 12, 2)->default(0);
            $t->decimal('discount_total', 12, 2)->default(0);
            $t->decimal('total_tax', 12, 2)->default(0);
            $t->string('payment_method', 64)->default('');
            $t->string('payment_method_title', 128)->default('');
            $t->string('shipping_method', 128)->default('');
            $t->string('customer_name', 191)->default('');
            $t->string('customer_email', 191)->default('');
            $t->string('customer_phone', 64)->default('');
            $t->json('billing_address')->nullable();
            $t->json('shipping_address')->nullable();
            $t->json('shipping_lines')->nullable();
            $t->string('tracking_number', 190)->default('');
            $t->text('customer_note')->nullable();
            $t->timestamp('order_created_at')->nullable();
            $t->timestamp('order_updated_at')->nullable();
            $t->unsignedInteger('catalog_order_id')->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();
            $t->unique(['woocommerce_setting_id', 'woo_order_id'], 'woo_orders_store_order_unique');
            $t->foreign('woocommerce_setting_id', 'woo_order_store_fk')->references('id')->on('woocommerce_settings')->cascadeOnDelete();
        });

        Schema::create('woocommerce_order_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_order_id');
            $t->unsignedBigInteger('woo_line_id');
            $t->unsignedBigInteger('woo_product_id')->default(0);
            $t->unsignedBigInteger('woo_variation_id')->default(0);
            $t->string('name', 255)->default('');
            $t->string('sku', 128)->default('');
            $t->string('variant_label', 255)->default('');
            $t->integer('quantity')->default(0);
            $t->decimal('price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0);
            $t->unsignedInteger('product_id')->nullable();
            $t->unsignedInteger('product_option_value_id')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->foreign('woocommerce_order_id', 'woo_op_parent_fk')->references('id')->on('woocommerce_orders')->cascadeOnDelete();
        });

        Schema::create('woocommerce_order_status_map', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_setting_id');
            $t->string('woo_status', 32);
            $t->unsignedInteger('order_status_id');
            $t->timestamps();
            $t->unique(['woocommerce_setting_id', 'woo_status'], 'woo_status_map_store_unique');
            $t->foreign('woocommerce_setting_id', 'woo_smap_store_fk')->references('id')->on('woocommerce_settings')->cascadeOnDelete();
        });

        Schema::create('woocommerce_sync_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_setting_id')->nullable();
            $t->string('entity_type', 32);
            $t->string('direction', 8);
            $t->string('status', 16)->default('started');
            $t->unsignedInteger('records_processed')->default(0);
            $t->unsignedInteger('records_created')->default(0);
            $t->unsignedInteger('records_updated')->default(0);
            $t->unsignedInteger('records_skipped')->default(0);
            $t->unsignedInteger('records_failed')->default(0);
            $t->text('error_message')->nullable();
            $t->json('details')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->foreign('woocommerce_setting_id', 'woo_slog_store_fk')->references('id')->on('woocommerce_settings')->nullOnDelete();
        });

        Schema::create('woocommerce_api_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woocommerce_setting_id')->nullable();
            $t->string('method', 10);
            $t->string('endpoint', 500);
            $t->unsignedSmallInteger('status_code')->default(0);
            $t->unsignedInteger('response_time_ms')->default(0);
            $t->json('request_body')->nullable();
            $t->json('response_body')->nullable();
            $t->boolean('ok')->default(false);
            $t->timestamps();
            $t->index('woocommerce_setting_id');
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        foreach ([
            'woocommerce_api_logs', 'woocommerce_sync_logs', 'woocommerce_order_status_map', 'woocommerce_order_products',
            'woocommerce_orders', 'woocommerce_listings', 'woocommerce_product_links', 'woocommerce_product_group_products',
            'woocommerce_product_groups', 'woocommerce_categories', 'woocommerce_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
