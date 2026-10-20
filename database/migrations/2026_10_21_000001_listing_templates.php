<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table, string $stores, string $channel) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('name', 64);
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->unsignedBigInteger('watermark_template_id')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'name'], "ch_{$channel}_listing_templates_name_unique");
            $table->foreign('store_id', "ch_{$channel}_listing_templates_store_foreign")->references('id')->on($stores)->cascadeOnDelete();
        };

        Schema::create('ch_shopee_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_shopee_stores', 'shopee');
            $table->unsignedBigInteger('shopee_category_id')->nullable();
            $table->unsignedBigInteger('shopee_brand_id')->nullable();
            $table->json('logistic_ids')->nullable();
        });

        Schema::create('ch_lazada_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_lazada_stores', 'lazada');
            $table->unsignedBigInteger('primary_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('brand_name_override', 255)->nullable();
        });

        Schema::create('ch_tiktok_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_tiktok_stores', 'tiktok');
            $table->string('tiktok_category_id', 255)->nullable();
        });

        Schema::create('ch_woocommerce_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_woocommerce_stores', 'woocommerce');
            $table->unsignedBigInteger('woo_category_id')->nullable();
        });

        Schema::create('ch_ventacart_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_ventacart_stores', 'ventacart');
            $table->unsignedBigInteger('ventacart_category_id')->nullable();
        });

        foreach (['shopee', 'lazada', 'tiktok'] as $channel) {
            Schema::create("ch_{$channel}_listing_template_attributes", function (Blueprint $table) use ($channel) {
                $table->id();
                $table->unsignedBigInteger('listing_template_id');
                $table->string('attribute_key', 191);
                $table->text('value')->nullable();
                $table->timestamps();
                $table->unique(['listing_template_id', 'attribute_key'], "ch_{$channel}_lt_attributes_unique");
                $table->foreign('listing_template_id', "ch_{$channel}_lt_attributes_template_foreign")->references('id')->on("ch_{$channel}_listing_templates")->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['shopee', 'lazada', 'tiktok'] as $channel) {
            Schema::dropIfExists("ch_{$channel}_listing_template_attributes");
        }
        foreach (['shopee', 'lazada', 'tiktok', 'woocommerce', 'ventacart'] as $channel) {
            Schema::dropIfExists("ch_{$channel}_listing_templates");
        }
    }
};
