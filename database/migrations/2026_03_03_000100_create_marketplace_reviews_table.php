<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_reviews', function (Blueprint $table) {
            $table->id();

            // Source marketplace
            $table->string('platform', 20)->index();           // shopee | lazada
            $table->string('platform_review_id', 128);          // Shopee comment_id or Lazada review_id

            // Link to ERP product (resolved via shopee_product_links / lazada_products)
            $table->unsignedInteger('product_id')->nullable()->index();

            // Platform-specific IDs
            $table->string('platform_item_id', 64)->nullable(); // shopee_item_id or lazada_item_id
            $table->string('platform_order_id', 64)->nullable();

            // Review content
            $table->string('author', 255)->nullable();
            $table->unsignedTinyInteger('rating');               // 1-5
            $table->text('comment')->nullable();
            $table->json('images')->nullable();                  // array of CDN URLs
            $table->json('videos')->nullable();                  // array of CDN URLs

            // Seller reply (synced from marketplace)
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();

            // OpenCart push tracking
            $table->string('oc_sync_status', 20)->default('pending')->index(); // pending|pushed|skipped|error
            $table->unsignedBigInteger('opencart_setting_id')->nullable();
            $table->unsignedInteger('oc_review_id')->nullable();
            $table->timestamp('oc_pushed_at')->nullable();
            $table->string('oc_push_error', 500)->nullable();

            // Original API response
            $table->json('raw')->nullable();

            // When the review was created on the marketplace
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Duplicate detection
            $table->unique(['platform', 'platform_review_id'], 'mr_platform_review_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_reviews');
    }
};
