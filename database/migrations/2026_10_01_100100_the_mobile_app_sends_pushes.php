<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobileapp_users')) {
            Schema::create('mobileapp_users', function (Blueprint $table) {
                $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
                $table->boolean('sign_in')->default(true);
                $table->boolean('pushes')->default(true);
                $table->boolean('low_stock')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mobileapp_user_statuses')) {
            Schema::create('mobileapp_user_statuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('order_status_id');
                $table->unique(['user_id', 'order_status_id']);
                $table->index('order_status_id');
            });
        }

        if (! Schema::hasTable('mobileapp_outbox')) {
            Schema::create('mobileapp_outbox', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('device_token_id')->constrained('device_tokens')->cascadeOnDelete();
                $table->string('kind', 20);
                $table->string('title', 191);
                $table->string('body', 191);
                $table->json('data');
                $table->string('status', 10)->default('pending');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('error', 255)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'id']);
                $table->index(['device_token_id', 'id']);
            });
        }

        if (! Schema::hasTable('mobileapp_low_stock')) {
            Schema::create('mobileapp_low_stock', function (Blueprint $table) {
                $table->unsignedInteger('product_id');
                $table->string('variation', 191)->default('');
                $table->timestamp('created_at')->nullable();
                $table->primary(['product_id', 'variation']);
            });
        }

        if (! Schema::hasTable('mobileapp_state')) {
            Schema::create('mobileapp_state', function (Blueprint $table) {
                $table->string('key', 64)->primary();
                $table->string('value', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mobileapp_state');
        Schema::dropIfExists('mobileapp_low_stock');
        Schema::dropIfExists('mobileapp_outbox');
        Schema::dropIfExists('mobileapp_user_statuses');
        Schema::dropIfExists('mobileapp_users');
    }
};
