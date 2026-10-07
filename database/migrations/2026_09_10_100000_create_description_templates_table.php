<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('description_templates')) {
            Schema::create('description_templates', function (Blueprint $table) {
                $table->id();
                $table->string('integration', 32);
                $table->unsignedBigInteger('store_id');
                $table->string('name', 128);
                $table->text('body')->nullable();
                $table->timestamps();
                $table->index(['integration', 'store_id']);
            });
        }

        foreach ([
            'shopee_listings', 'lazada_products', 'tiktok_listings',
            'venta_listings', 'woocommerce_listings',
        ] as $listings) {
            if (! Schema::hasTable($listings)) {
                continue;
            }
            Schema::table($listings, function (Blueprint $table) use ($listings) {
                if (! Schema::hasColumn($listings, 'description_prefix_id')) {
                    $table->unsignedBigInteger('description_prefix_id')->nullable();
                }
                if (! Schema::hasColumn($listings, 'description_suffix_id')) {
                    $table->unsignedBigInteger('description_suffix_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'shopee_listings', 'lazada_products', 'tiktok_listings',
            'venta_listings', 'woocommerce_listings',
        ] as $listings) {
            if (! Schema::hasTable($listings)) {
                continue;
            }
            Schema::table($listings, function (Blueprint $table) use ($listings) {
                foreach (['description_prefix_id', 'description_suffix_id'] as $column) {
                    if (Schema::hasColumn($listings, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
        Schema::dropIfExists('description_templates');
    }
};
