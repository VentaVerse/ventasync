<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobileapp_users', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->boolean('sign_in')->default(1);
            $table->boolean('pushes')->default(1);
            $table->boolean('low_stock')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['user_id']);
        });

        Schema::create('mobileapp_user_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('order_status_id');
            $table->index(['order_status_id'], 'mobileapp_user_statuses_order_status_id_index');
            $table->unique(['user_id', 'order_status_id'], 'mobileapp_user_statuses_user_id_order_status_id_unique');
        });

        Schema::create('mobileapp_state', function (Blueprint $table) {
            $table->string('key', 64);
            $table->string('value', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['key']);
        });

        Schema::create('mobileapp_outbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('device_token_id');
            $table->string('kind', 20);
            $table->string('title', 191);
            $table->string('body', 191);
            $table->json('data');
            $table->string('status', 10)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['device_token_id', 'id'], 'mobileapp_outbox_device_token_id_id_index');
            $table->index(['status', 'id'], 'mobileapp_outbox_status_id_index');
        });

        Schema::create('mobileapp_low_stock', function (Blueprint $table) {
            $table->unsignedInteger('product_id');
            $table->string('variation', 191)->default('');
            $table->timestamp('created_at')->nullable();
            $table->primary(['product_id', 'variation']);
        });

        Schema::table('mobileapp_users', function (Blueprint $table) {
            $table->foreign('user_id', 'mobileapp_users_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('mobileapp_user_statuses', function (Blueprint $table) {
            $table->foreign('user_id', 'mobileapp_user_statuses_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('mobileapp_outbox', function (Blueprint $table) {
            $table->foreign('device_token_id', 'mobileapp_outbox_device_token_id_foreign')->references('id')->on('device_tokens')->onDelete('cascade');
            $table->foreign('user_id', 'mobileapp_outbox_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('mobileapp_low_stock');
        Schema::dropIfExists('mobileapp_outbox');
        Schema::dropIfExists('mobileapp_state');
        Schema::dropIfExists('mobileapp_user_statuses');
        Schema::dropIfExists('mobileapp_users');
        Schema::enableForeignKeyConstraints();
    }
};
