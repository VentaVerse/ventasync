<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_settings', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 128);
            $table->string('shop_domain', 255)->unique();
            $table->text('access_token');
            $table->unsignedBigInteger('primary_location_id')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('country_code', 4)->nullable();
            $table->string('api_version', 16)->default('2024-10');
            $table->boolean('enabled')->default(true);
            $table->boolean('api_logging')->default(false);
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedInteger('sync_last_days')->default(30);
            $table->date('sync_orders_from')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_setting_id');
            $table->unsignedBigInteger('shopify_order_id')->unique();
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
            $table->unsignedBigInteger('erp_order_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('shopify_setting_id')
                ->references('id')->on('shopify_settings')
                ->cascadeOnDelete();
        });

        Schema::create('shopify_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_order_id_local');
            $table->unsignedBigInteger('shopify_line_item_id')->nullable();
            $table->unsignedBigInteger('shopify_variant_id')->nullable();
            $table->unsignedBigInteger('shopify_product_id')->nullable();
            $table->string('sku', 100)->nullable()->index();
            $table->string('title', 255)->nullable();
            $table->string('variant_title', 255)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->timestamps();

            $table->foreign('shopify_order_id_local')
                ->references('id')->on('shopify_orders')
                ->cascadeOnDelete();
        });

        Schema::create('shopify_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_setting_id');
            $table->string('shopify_status', 64);
            $table->string('context', 16)->default('order');
            $table->unsignedInteger('order_status_id')->default(0);
            $table->timestamps();

            $table->unique(['shopify_setting_id', 'shopify_status', 'context'], 'shopify_status_map_unique');
            $table->foreign('shopify_setting_id')
                ->references('id')->on('shopify_settings')
                ->cascadeOnDelete();
        });

        Schema::create('shopify_product_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_setting_id');
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('shopify_product_id');
            $table->unsignedBigInteger('shopify_variant_id')->nullable();
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->string('sku', 100)->nullable();
            $table->timestamps();

            $table->unique(['shopify_setting_id', 'product_id'], 'shopify_link_setting_product_unique');
            $table->index('shopify_variant_id');
            $table->foreign('shopify_setting_id')
                ->references('id')->on('shopify_settings')
                ->cascadeOnDelete();
        });

        Schema::create('shopify_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_setting_id')->nullable();
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
            $table->timestamps();

            $table->index(['shopify_setting_id', 'created_at']);
            $table->foreign('shopify_setting_id')
                ->references('id')->on('shopify_settings')
                ->nullOnDelete();
        });

        Schema::create('shopify_api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_setting_id')->nullable();
            $table->string('method', 8);
            $table->string('endpoint', 255);
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->unsignedInteger('response_time_ms')->default(0);
            $table->boolean('ok')->default(false);
            $table->timestamps();

            $table->index(['shopify_setting_id', 'created_at']);
            $table->foreign('shopify_setting_id')
                ->references('id')->on('shopify_settings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_api_logs');
        Schema::dropIfExists('shopify_sync_logs');
        Schema::dropIfExists('shopify_product_links');
        Schema::dropIfExists('shopify_order_status_map');
        Schema::dropIfExists('shopify_order_products');
        Schema::dropIfExists('shopify_orders');
        Schema::dropIfExists('shopify_settings');
    }
};
