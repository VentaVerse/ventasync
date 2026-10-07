<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_settings', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 128);
            $table->string('base_url', 255);
            $table->text('api_token');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedInteger('sync_last_days')->default(30);
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_category_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamps();
        });

        Schema::create('venta_product_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_setting_id');
            $table->string('name');
            $table->json('catalog_category_ids')->nullable();
            $table->json('manufacturer_ids')->nullable();
            $table->timestamps();

            $table->foreign('venta_setting_id')
                ->references('id')
                ->on('venta_settings')
                ->cascadeOnDelete();
        });

        Schema::create('venta_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_product_group_id');
            $table->unsignedInteger('product_id');
            $table->string('venta_sku', 100)->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('last_pushed_at')->nullable();
            $table->text('push_error')->nullable();
            $table->timestamps();

            $table->unique(['venta_product_group_id', 'product_id'], 'venta_pgp_group_product_unique');

            $table->foreign('venta_product_group_id')
                ->references('id')
                ->on('venta_product_groups')
                ->cascadeOnDelete();
        });

        Schema::create('venta_product_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_setting_id');
            $table->unsignedBigInteger('venta_product_id');
            $table->unsignedInteger('product_id');
            $table->string('sku', 100)->index();
            $table->timestamps();

            $table->unique(['venta_setting_id', 'venta_product_id'], 'venta_link_store_product_unique');

            $table->foreign('venta_setting_id')
                ->references('id')
                ->on('venta_settings')
                ->cascadeOnDelete();
        });

        Schema::create('venta_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_setting_id')->index();
            $table->unsignedBigInteger('venta_order_id')->index();
            $table->unsignedBigInteger('venta_order_number')->nullable();
            $table->string('status', 50)->nullable()->index();
            $table->unsignedInteger('status_id')->nullable();
            $table->string('customer_name', 200)->nullable();
            $table->string('customer_email', 200)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('payment_method', 64)->nullable();
            $table->string('shipping_method', 64)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('raw')->nullable();
            $table->unsignedBigInteger('catalog_order_id')->nullable()->index();
            $table->timestamp('order_created_at')->nullable()->index();
            $table->timestamp('order_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['venta_setting_id', 'venta_order_id'], 'venta_order_store_unique');

            $table->foreign('venta_setting_id')
                ->references('id')
                ->on('venta_settings')
                ->cascadeOnDelete();
        });

        Schema::create('venta_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_order_id')->index();
            $table->string('sku', 100)->nullable();
            $table->string('name', 500)->nullable();
            $table->string('variant_label', 255)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->foreign('venta_order_id')
                ->references('id')
                ->on('venta_orders')
                ->cascadeOnDelete();
        });

        Schema::create('venta_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_setting_id');
            $table->unsignedInteger('venta_status_id');
            $table->string('venta_status_name', 64)->default('');
            $table->unsignedInteger('order_status_id');
            $table->timestamps();

            $table->unique(['venta_setting_id', 'venta_status_id'], 'venta_status_map_store_unique');

            $table->foreign('venta_setting_id')
                ->references('id')
                ->on('venta_settings')
                ->cascadeOnDelete();
        });

        Schema::create('venta_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_setting_id')->nullable();
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
            $table->timestamps();

            $table->foreign('venta_setting_id')
                ->references('id')
                ->on('venta_settings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_sync_logs');
        Schema::dropIfExists('venta_order_status_map');
        Schema::dropIfExists('venta_order_products');
        Schema::dropIfExists('venta_orders');
        Schema::dropIfExists('venta_product_links');
        Schema::dropIfExists('venta_product_group_products');
        Schema::dropIfExists('venta_product_groups');
        Schema::dropIfExists('venta_settings');
    }
};
