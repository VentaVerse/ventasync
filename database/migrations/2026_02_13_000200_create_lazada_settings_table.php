<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lazada_settings', function (Blueprint $table) {
            $table->id();
            $table->string('region', 16)->nullable();
            $table->string('app_key', 64)->nullable();
            $table->text('app_secret')->nullable(); // encrypted
            $table->string('redirect_uri', 255)->nullable();
            $table->text('access_token')->nullable(); // encrypted
            $table->text('refresh_token')->nullable(); // encrypted
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->string('account', 64)->nullable();
            $table->string('country', 8)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_settings');
    }
};
