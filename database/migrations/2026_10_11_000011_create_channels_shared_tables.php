<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ch_channel_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 24);
            $table->string('event_type', 64)->nullable();
            $table->json('payload');
            $table->string('signature', 512)->nullable();
            $table->boolean('signature_ok')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 255)->nullable();
            $table->index(['channel'], 'ch_channel_webhook_events_channel_index');
            $table->index(['event_type'], 'ch_channel_webhook_events_event_type_index');
            $table->index(['processed_at'], 'ch_channel_webhook_events_processed_at_index');
            $table->index(['signature_ok'], 'ch_channel_webhook_events_signature_ok_index');
        });

        Schema::create('ch_description_templates', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->unsignedBigInteger('store_id');
            $table->string('name', 128);
            $table->text('body')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['channel', 'store_id'], 'ch_description_templates_channel_store_id_index');
        });

        Schema::create('ch_watermark_templates', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 40)->default('');
            $table->unsignedBigInteger('store_id')->default(0);
            $table->string('name', 255);
            $table->string('image_path', 255);
            $table->string('position', 20)->default('bottom-right');
            $table->decimal('size_percent', 5, 2)->default(18);
            $table->decimal('offset_x_percent', 5, 2)->default(0);
            $table->decimal('offset_y_percent', 5, 2)->default(0);
            $table->decimal('opacity', 4, 3)->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['channel', 'store_id', 'name'], 'ch_watermark_templates_channel_store_id_name_unique');
        });

        Schema::create('ch_listing_readiness', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->unsignedInteger('store_id');
            $table->unsignedInteger('product_id');
            $table->boolean('ready')->default(0);
            $table->json('gaps')->nullable();
            $table->string('stamp', 64)->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->unique(['channel', 'store_id', 'product_id'], 'ch_listing_readiness_channel_store_id_product_id_unique');
            $table->index(['channel', 'store_id', 'ready'], 'ch_listing_readiness_channel_store_id_ready_index');
        });

        Schema::create('ch_listing_store_skus', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id');
            $table->json('skus');
            $table->timestamp('checked_at')->nullable();
            $table->unique(['channel', 'store_id', 'product_id'], 'ch_listing_store_skus_channel_store_id_product_id_unique');
            $table->index(['product_id'], 'ch_listing_store_skus_product_id_index');
        });

        Schema::create('ch_listing_variation_offs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id');
            $table->string('sku', 100);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['channel', 'store_id', 'product_id', 'sku'], 'ch_listing_variation_offs_channel_store_id_product_id_sku_unique');
            $table->index(['product_id'], 'ch_listing_variation_offs_product_id_index');
        });

        Schema::create('ch_marketplace_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            $table->string('channel_review_id', 128);
            $table->unsignedInteger('product_id')->nullable();
            $table->string('channel_item_id', 64)->nullable();
            $table->string('channel_order_id', 64)->nullable();
            $table->string('author', 255)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->json('images')->nullable();
            $table->json('videos')->nullable();
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->string('oc_sync_status', 20)->default('pending');
            $table->unsignedBigInteger('opencart_store_id')->nullable();
            $table->unsignedInteger('oc_review_id')->nullable();
            $table->timestamp('oc_pushed_at')->nullable();
            $table->string('oc_push_error', 500)->nullable();
            $table->string('ventacart_sync_status', 20)->default('pending');
            $table->unsignedBigInteger('ventacart_store_id')->nullable();
            $table->unsignedBigInteger('ventacart_review_id')->nullable();
            $table->timestamp('ventacart_pushed_at')->nullable();
            $table->string('ventacart_push_error', 500)->nullable();
            $table->string('woocommerce_sync_status', 20)->default('pending');
            $table->unsignedBigInteger('woocommerce_store_id')->nullable();
            $table->unsignedBigInteger('woocommerce_review_id')->nullable();
            $table->timestamp('woocommerce_pushed_at')->nullable();
            $table->string('woocommerce_push_error', 500)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['channel', 'channel_review_id'], 'ch_marketplace_reviews_channel_channel_review_id_unique');
            $table->index(['channel'], 'ch_marketplace_reviews_channel_index');
            $table->index(['oc_sync_status'], 'ch_marketplace_reviews_oc_sync_status_index');
            $table->index(['product_id'], 'ch_marketplace_reviews_product_id_index');
            $table->index(['ventacart_sync_status'], 'ch_marketplace_reviews_ventacart_sync_status_index');
            $table->index(['woocommerce_sync_status'], 'ch_marketplace_reviews_woocommerce_sync_status_index');
        });

        Schema::create('ch_order_fetch_runs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->date('date_from');
            $table->date('date_to');
            $table->json('options')->nullable();
            $table->json('cursor')->nullable();
            $table->unsignedInteger('page')->default(0);
            $table->unsignedInteger('pages')->nullable();
            $table->unsignedInteger('total')->nullable();
            $table->unsignedInteger('read')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 16)->default('running');
            $table->text('last_error')->nullable();
            $table->json('ledger')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['channel', 'store_id', 'status'], 'ch_order_fetch_runs_channel_store_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ch_order_fetch_runs');
        Schema::dropIfExists('ch_marketplace_reviews');
        Schema::dropIfExists('ch_listing_variation_offs');
        Schema::dropIfExists('ch_listing_store_skus');
        Schema::dropIfExists('ch_listing_readiness');
        Schema::dropIfExists('ch_watermark_templates');
        Schema::dropIfExists('ch_description_templates');
        Schema::dropIfExists('ch_channel_webhook_events');
        Schema::enableForeignKeyConstraints();
    }
};
