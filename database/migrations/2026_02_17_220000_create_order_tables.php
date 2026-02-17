<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('catalog.prefix');

        // ── order ──
        if (!Schema::hasTable($p.'order')) {
            Schema::create($p.'order', function (Blueprint $t) {
                $t->integer('order_id')->autoIncrement();
                $t->integer('invoice_no')->default(0);
                $t->string('invoice_prefix', 26);
                $t->integer('store_id')->default(0);
                $t->string('store_name', 64);
                $t->string('store_url', 255);
                $t->integer('customer_id')->default(0);
                $t->integer('customer_group_id')->default(0);
                $t->string('firstname', 32);
                $t->string('lastname', 32);
                $t->string('email', 96);
                $t->string('telephone', 32);
                $t->string('fax', 32);
                $t->text('custom_field');
                $t->string('payment_firstname', 32);
                $t->string('payment_lastname', 32);
                $t->string('payment_company', 40);
                $t->string('payment_address_1', 128);
                $t->string('payment_address_2', 128);
                $t->string('payment_city', 128);
                $t->string('payment_postcode', 10);
                $t->string('payment_country', 128);
                $t->integer('payment_country_id');
                $t->string('payment_zone', 128);
                $t->integer('payment_zone_id');
                $t->text('payment_address_format');
                $t->text('payment_custom_field');
                $t->string('payment_method', 128);
                $t->decimal('payment_cost', 15, 4)->default(0);
                $t->string('payment_code', 128);
                $t->string('shipping_firstname', 32);
                $t->string('shipping_lastname', 32);
                $t->string('shipping_company', 40);
                $t->string('shipping_address_1', 128);
                $t->string('shipping_address_2', 128);
                $t->string('shipping_city', 128);
                $t->string('shipping_postcode', 10);
                $t->string('shipping_country', 128);
                $t->integer('shipping_country_id');
                $t->string('shipping_zone', 128);
                $t->integer('shipping_zone_id');
                $t->text('shipping_address_format');
                $t->text('shipping_custom_field');
                $t->text('shipping_method');
                $t->decimal('shipping_cost', 15, 4)->default(0);
                $t->string('shipping_code', 128);
                $t->text('comment');
                $t->decimal('total', 15, 4)->default(0);
                $t->decimal('extra_cost', 15, 4)->default(0);
                $t->integer('order_status_id')->default(0);
                $t->integer('affiliate_id');
                $t->decimal('commission', 15, 4);
                $t->integer('marketing_id');
                $t->string('tracking', 64);
                $t->integer('language_id');
                $t->integer('currency_id');
                $t->string('currency_code', 3);
                $t->decimal('currency_value', 15, 8)->default(1);
                $t->string('ip', 40);
                $t->string('forwarded_ip', 40);
                $t->string('user_agent', 255);
                $t->string('accept_language', 255);
                $t->integer('courier_id');
                $t->string('tracking_number', 32);
                $t->dateTime('date_added');
                $t->dateTime('date_modified');
                $t->tinyInteger('oe_import');
                $t->string('marketplace_source', 32)->default('');
                $t->string('marketplace_order_id', 64)->default('');

                $t->index('store_id');
                $t->index('customer_id');
                $t->index('customer_group_id');
                $t->index('payment_country_id');
                $t->index('payment_zone_id');
                $t->index('shipping_country_id');
                $t->index('shipping_zone_id');
                $t->index('order_status_id');
                $t->index('affiliate_id');
                $t->index('marketing_id');
                $t->index('language_id');
                $t->index('currency_id');
                $t->index('courier_id');
                $t->index('marketplace_source');
                $t->index('marketplace_order_id');
            });
        }

        // ── order_history ──
        if (!Schema::hasTable($p.'order_history')) {
            Schema::create($p.'order_history', function (Blueprint $t) {
                $t->integer('order_history_id')->autoIncrement();
                $t->integer('order_id');
                $t->integer('order_status_id');
                $t->tinyInteger('notify')->default(0);
                $t->text('comment');
                $t->dateTime('date_added');
                $t->string('slug', 255)->default('');
                $t->string('tracking_number', 255)->default('');
                $t->string('powertrack_carrier', 64)->nullable();
                $t->string('powertrack_trackcode', 64)->nullable();

                $t->index('order_id');
                $t->index('order_status_id');
            });
        }

        // ── order_product ──
        if (!Schema::hasTable($p.'order_product')) {
            Schema::create($p.'order_product', function (Blueprint $t) {
                $t->integer('order_product_id')->autoIncrement();
                $t->integer('order_id');
                $t->integer('product_id');
                $t->string('name', 255);
                $t->string('model', 64);
                $t->integer('quantity');
                $t->decimal('price', 15, 4)->default(0);
                $t->decimal('total', 15, 4)->default(0);
                $t->decimal('tax', 15, 4)->default(0);
                $t->integer('reward');
                $t->decimal('base_price', 15, 4)->default(0);
                $t->decimal('cost', 15, 4)->default(0);
                $t->integer('supplier_id')->default(0);

                $t->index('order_id');
                $t->index('product_id');
                $t->index('supplier_id');
            });
        }

        // ── order_option ──
        if (!Schema::hasTable($p.'order_option')) {
            Schema::create($p.'order_option', function (Blueprint $t) {
                $t->integer('order_option_id')->autoIncrement();
                $t->integer('order_id');
                $t->integer('order_product_id');
                $t->integer('product_option_id');
                $t->integer('product_option_value_id')->default(0);
                $t->string('name', 255);
                $t->text('value');
                $t->string('type', 32);

                $t->index('order_id');
                $t->index('order_product_id');
                $t->index('product_option_id');
                $t->index('product_option_value_id');
            });
        }

        // ── order_total ──
        if (!Schema::hasTable($p.'order_total')) {
            Schema::create($p.'order_total', function (Blueprint $t) {
                $t->integer('order_total_id')->autoIncrement();
                $t->integer('order_id');
                $t->string('code', 32);
                $t->string('title', 255);
                $t->decimal('value', 15, 4)->default(0);
                $t->integer('sort_order');

                $t->index('order_id');
            });
        }
    }

    public function down(): void
    {
        // Intentionally not dropping catalog tables.
    }
};
