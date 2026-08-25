<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok listings parity (#156, 2026-08-26). tiktok_listings was born
 * holding only the discount rule; it becomes the product's TikTok
 * record the way shopee_listings is Shopee's: the settings an
 * individual push uses (category, brand, attribute answers, markups,
 * content overrides), the truth (the TikTok product id and its SKU
 * map, written by every push whether from a group or from the listing
 * page), and provenance. Group pivots keep their per-group attempt
 * history and stay a fallback source of truth for products pushed
 * before this record existed.
 *
 * tiktok_category_templates caches a category's attribute sheet as
 * TikTok returns it; tiktok_product_group_attributes holds a group's
 * answers, the listing's own answers live in attribute_values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_listings', function (Blueprint $table) {
            $table->string('tiktok_product_id', 64)->nullable()->index()->after('product_id');
            $table->text('tiktok_sku_id')->nullable()->after('tiktok_product_id');
            $table->string('tiktok_category_id', 64)->nullable()->after('tiktok_sku_id');
            $table->string('brand_id', 64)->nullable()->after('tiktok_category_id');
            $table->string('brand_name', 191)->nullable()->after('brand_id');
            $table->json('attribute_values')->nullable()->after('brand_name');
            $table->string('title', 255)->nullable()->after('attribute_values');
            $table->text('description')->nullable()->after('title');
            $table->decimal('markup_percent', 8, 2)->nullable()->after('description');
            $table->decimal('markup_fixed', 12, 2)->nullable()->after('markup_percent');
            $table->timestamp('last_pushed_at')->nullable()->after('tiktok_activity_id');
            $table->string('last_push_source', 191)->nullable()->after('last_pushed_at');
            $table->timestamp('last_checked_at')->nullable()->after('last_push_source');
        });

        Schema::create('tiktok_category_templates', function (Blueprint $table) {
            $table->id();
            $table->string('category_id', 64)->unique();
            $table->json('attributes')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tiktok_product_group_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tiktok_product_group_id');
            $table->string('attribute_key', 64);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['tiktok_product_group_id', 'attribute_key'], 'tiktok_group_attr_unique');
            $table->foreign('tiktok_product_group_id')->references('id')->on('tiktok_product_groups')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_product_group_attributes');
        Schema::dropIfExists('tiktok_category_templates');
        Schema::table('tiktok_listings', function (Blueprint $table) {
            $table->dropColumn([
                'tiktok_product_id', 'tiktok_sku_id', 'tiktok_category_id', 'brand_id', 'brand_name',
                'attribute_values', 'title', 'description', 'markup_percent', 'markup_fixed',
                'last_pushed_at', 'last_push_source', 'last_checked_at',
            ]);
        });
    }
};
