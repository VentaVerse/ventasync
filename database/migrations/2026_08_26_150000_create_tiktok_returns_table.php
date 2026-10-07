<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_id', 64)->unique();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('return_type', 64)->nullable();
            $table->string('return_status', 64)->nullable()->index();
            $table->string('reason', 255)->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->timestamp('return_created_at')->nullable();
            $table->timestamp('return_updated_at')->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->json('items')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_returns');
    }
};
