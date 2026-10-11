<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->integer('order_status_id', true);
            $table->integer('language_id')->default(1);
            $table->string('name', 32);
            $table->tinyInteger('subtract_stock')->default(0);
            $table->tinyInteger('add_revenue')->default(0);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->integer('order_id', true);
            $table->integer('invoice_no')->default(0);
            $table->string('invoice_prefix', 26);
            $table->integer('store_id')->default(0);
            $table->string('store_name', 64);
            $table->string('store_url', 255);
            $table->integer('customer_id')->default(0);
            $table->integer('customer_group_id')->default(0);
            $table->string('customer_group', 64)->nullable();
            $table->string('firstname', 32);
            $table->string('lastname', 32);
            $table->string('email', 96);
            $table->string('telephone', 32);
            $table->string('fax', 32);
            $table->text('custom_field');
            $table->string('payment_firstname', 32);
            $table->string('payment_lastname', 32);
            $table->string('payment_company', 40);
            $table->string('payment_address_1', 256)->default('');
            $table->string('payment_address_2', 256)->default('');
            $table->string('payment_city', 128);
            $table->string('payment_postcode', 10);
            $table->string('payment_country', 128);
            $table->integer('payment_country_id');
            $table->string('payment_zone', 128);
            $table->integer('payment_zone_id');
            $table->text('payment_address_format');
            $table->text('payment_custom_field');
            $table->string('payment_method', 128);
            $table->decimal('payment_cost', 15, 4)->default(0);
            $table->string('payment_code', 128);
            $table->string('shipping_firstname', 32);
            $table->string('shipping_lastname', 32);
            $table->string('shipping_company', 40);
            $table->string('shipping_address_1', 256)->default('');
            $table->string('shipping_address_2', 256)->default('');
            $table->string('shipping_city', 128);
            $table->string('shipping_postcode', 10);
            $table->string('shipping_country', 128);
            $table->integer('shipping_country_id');
            $table->string('shipping_zone', 128);
            $table->integer('shipping_zone_id');
            $table->text('shipping_address_format');
            $table->text('shipping_custom_field');
            $table->text('shipping_method');
            $table->decimal('shipping_cost', 15, 4)->default(0);
            $table->string('shipping_code', 128);
            $table->text('comment');
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('foreign_total', 15, 4)->nullable();
            $table->decimal('extra_cost', 15, 4)->default(0);
            $table->integer('order_status_id')->default(0);
            $table->tinyInteger('sync_override')->default(0);
            $table->integer('affiliate_id');
            $table->decimal('commission', 15, 4);
            $table->integer('marketing_id');
            $table->string('tracking', 64);
            $table->integer('language_id');
            $table->integer('currency_id');
            $table->string('currency_code', 3);
            $table->decimal('currency_value', 15, 8)->default(1);
            $table->string('ip', 40);
            $table->string('forwarded_ip', 40);
            $table->string('user_agent', 255);
            $table->string('accept_language', 255);
            $table->integer('courier_id');
            $table->string('tracking_number', 32);
            $table->string('tracking_url', 500)->nullable();
            $table->dateTime('date_added');
            $table->dateTime('date_modified');
            $table->tinyInteger('oe_import');
            $table->string('channel', 32)->default('');
            $table->string('marketplace_order_id', 64)->default('');
            $table->boolean('track_payments')->default(0);
            $table->index(['affiliate_id'], 'orders_affiliate_id_index');
            $table->index(['channel'], 'orders_channel_index');
            $table->index(['courier_id'], 'orders_courier_id_index');
            $table->index(['currency_id'], 'orders_currency_id_index');
            $table->index(['customer_group_id'], 'orders_customer_group_id_index');
            $table->index(['customer_id'], 'orders_customer_id_index');
            $table->index(['language_id'], 'orders_language_id_index');
            $table->index(['marketing_id'], 'orders_marketing_id_index');
            $table->index(['marketplace_order_id'], 'orders_marketplace_order_id_index');
            $table->index(['order_status_id'], 'orders_order_status_id_index');
            $table->index(['payment_country_id'], 'orders_payment_country_id_index');
            $table->index(['payment_zone_id'], 'orders_payment_zone_id_index');
            $table->index(['shipping_country_id'], 'orders_shipping_country_id_index');
            $table->index(['shipping_zone_id'], 'orders_shipping_zone_id_index');
            $table->index(['store_id'], 'orders_store_id_index');
        });

        Schema::create('order_products', function (Blueprint $table) {
            $table->integer('order_product_id', true);
            $table->integer('order_id');
            $table->integer('product_id');
            $table->string('name', 255);
            $table->string('model', 64);
            $table->integer('quantity');
            $table->decimal('price', 15, 4)->default(0);
            $table->decimal('foreign_price', 15, 4)->nullable();
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('foreign_total', 15, 4)->nullable();
            $table->decimal('tax', 15, 4)->default(0);
            $table->integer('reward');
            $table->decimal('base_price', 15, 4)->default(0);
            $table->decimal('cost', 15, 4)->default(0);
            $table->integer('supplier_id')->default(0);
            $table->index(['order_id'], 'order_products_order_id_index');
            $table->index(['product_id'], 'order_products_product_id_index');
            $table->index(['supplier_id'], 'order_products_supplier_id_index');
        });

        Schema::create('order_variations', function (Blueprint $table) {
            $table->integer('order_variation_id', true);
            $table->integer('order_id');
            $table->integer('order_product_id');
            $table->integer('product_variation_type_id');
            $table->integer('product_variation_value_id')->default(0);
            $table->string('name', 255);
            $table->text('value');
            $table->string('type', 32);
            $table->index(['order_id'], 'order_variations_order_id_index');
            $table->index(['order_product_id'], 'order_variations_order_product_id_index');
            $table->index(['product_variation_type_id'], 'order_variations_product_variation_type_id_index');
            $table->index(['product_variation_value_id'], 'order_variations_product_variation_value_id_index');
        });

        Schema::create('order_totals', function (Blueprint $table) {
            $table->integer('order_total_id', true);
            $table->integer('order_id');
            $table->string('code', 32);
            $table->string('title', 255);
            $table->decimal('value', 15, 4)->default(0);
            $table->integer('sort_order');
            $table->index(['order_id'], 'order_totals_order_id_index');
        });

        Schema::create('order_history', function (Blueprint $table) {
            $table->integer('order_history_id', true);
            $table->integer('order_id');
            $table->integer('order_status_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 100)->nullable();
            $table->tinyInteger('notify')->default(0);
            $table->text('comment');
            $table->dateTime('date_added');
            $table->string('slug', 255)->default('');
            $table->string('tracking_number', 255)->default('');
            $table->string('powertrack_carrier', 64)->nullable();
            $table->string('powertrack_trackcode', 64)->nullable();
            $table->index(['order_id'], 'order_history_order_id_index');
            $table->index(['order_status_id'], 'order_history_order_status_id_index');
        });

        Schema::create('order_fees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('label', 128);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['order_id'], 'order_fees_order_id_index');
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->decimal('amount', 15, 4);
            $table->string('payment_method', 64);
            $table->date('paid_at');
            $table->string('reference_no', 128)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id'], 'order_payments_order_id_index');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_fees');
        Schema::dropIfExists('order_history');
        Schema::dropIfExists('order_totals');
        Schema::dropIfExists('order_variations');
        Schema::dropIfExists('order_products');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_statuses');
        Schema::enableForeignKeyConstraints();
    }
};
