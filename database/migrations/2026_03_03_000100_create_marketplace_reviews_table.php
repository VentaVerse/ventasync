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

            $table->string('platform', 20)->index();
            $table->string('platform_review_id', 128);

            $table->unsignedInteger('product_id')->nullable()->index();

            $table->string('platform_item_id', 64)->nullable();
            $table->string('platform_order_id', 64)->nullable();

            $table->string('author', 255)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->json('images')->nullable();
            $table->json('videos')->nullable();

            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->string('oc_sync_status', 20)->default('pending')->index();
            $table->unsignedBigInteger('opencart_setting_id')->nullable();
            $table->unsignedInteger('oc_review_id')->nullable();
            $table->timestamp('oc_pushed_at')->nullable();
            $table->string('oc_push_error', 500)->nullable();

            $table->json('raw')->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'platform_review_id'], 'mr_platform_review_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_reviews');
    }
};
