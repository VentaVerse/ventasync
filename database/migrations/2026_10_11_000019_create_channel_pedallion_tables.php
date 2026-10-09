<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ch_pedallion_stores', function (Blueprint $table) {
            $table->id();
            $table->string('base_url', 255)->default('https://api.pedallion.com/api/v1');
            $table->text('api_key')->nullable();
            $table->boolean('enabled')->default(0);
            $table->unsignedInteger('sync_last_days')->default(14);
            $table->timestamp('last_category_sync_at')->nullable();
            $table->timestamp('last_manufacturer_sync_at')->nullable();
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('api_log_mode', 10)->default('all');
        });

        Schema::create('ch_pedallion_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->string('endpoint', 255);
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['endpoint', 'created_at'], 'ch_pedallion_api_logs_endpoint_created_at_index');
        });

        Schema::create('ch_pedallion_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pedallion_category_id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('name', 255);
            $table->unsignedInteger('level')->default(0);
            $table->boolean('leaf')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['pedallion_category_id'], 'ch_pedallion_categories_pedallion_category_id_unique');
        });

        Schema::create('ch_pedallion_manufacturers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pedallion_manufacturer_id');
            $table->string('name', 255);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['pedallion_manufacturer_id'], 'ch_pedallion_manufacturers_pedallion_manufacturer_id_unique');
        });

        Schema::create('ch_pedallion_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pedallion_order_id');
            $table->string('pedallion_sku', 128);
            $table->string('product_name', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->unsignedInteger('product_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('ch_pedallion_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->string('pedallion_status', 64);
            $table->string('pedallion_status_label', 100)->nullable();
            $table->string('context', 16)->default('order');
            $table->unsignedInteger('order_status_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['pedallion_status', 'context'], 'ch_pedallion_order_status_map_pedallion_status_context_unique');
        });

        Schema::create('ch_pedallion_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 64);
            $table->string('status', 64)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->string('buyer_name', 128)->nullable();
            $table->text('shipping_address')->nullable();
            $table->timestamp('order_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->unsignedInteger('erp_order_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['order_number'], 'ch_pedallion_orders_order_number_unique');
        });

        Schema::create('ch_pedallion_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pedallion_product_group_id');
            $table->unsignedInteger('product_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['product_id'], 'ch_pedallion_product_group_products_product_id_unique');
            $table->unique(['pedallion_product_group_id', 'product_id'], 'ch_pedallion_product_group_products_unique');
        });

        Schema::create('ch_pedallion_product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->unsignedInteger('pedallion_category_id')->nullable();
            $table->string('condition', 32)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('ch_pedallion_product_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->string('pedallion_sku', 128);
            $table->string('sync_status', 32)->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['pedallion_sku'], 'ch_pedallion_product_links_pedallion_sku_index');
            $table->unique(['product_id', 'pedallion_sku'], 'ch_pedallion_product_links_product_id_pedallion_sku_unique');
        });

        Schema::table('ch_pedallion_order_products', function (Blueprint $table) {
            $table->foreign('pedallion_order_id', 'ch_pedallion_order_products_pedallion_order_id_foreign')->references('id')->on('ch_pedallion_orders')->onDelete('cascade');
        });

        Schema::table('ch_pedallion_product_group_products', function (Blueprint $table) {
            $table->foreign('pedallion_product_group_id', 'ch_pedallion_product_group_products_product_group_id_foreign')->references('id')->on('ch_pedallion_product_groups')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ch_pedallion_product_links');
        Schema::dropIfExists('ch_pedallion_product_groups');
        Schema::dropIfExists('ch_pedallion_product_group_products');
        Schema::dropIfExists('ch_pedallion_orders');
        Schema::dropIfExists('ch_pedallion_order_status_map');
        Schema::dropIfExists('ch_pedallion_order_products');
        Schema::dropIfExists('ch_pedallion_manufacturers');
        Schema::dropIfExists('ch_pedallion_categories');
        Schema::dropIfExists('ch_pedallion_api_logs');
        Schema::dropIfExists('ch_pedallion_stores');
        Schema::enableForeignKeyConstraints();
    }
};
