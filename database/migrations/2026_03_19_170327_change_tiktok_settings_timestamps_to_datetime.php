<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tiktok_settings', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable()->change();
            $table->dateTime('refresh_expires_at')->nullable()->change();
            $table->dateTime('sandbox_expires_at')->nullable()->change();
            $table->dateTime('sandbox_refresh_expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_settings', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
            $table->timestamp('refresh_expires_at')->nullable()->change();
            $table->timestamp('sandbox_expires_at')->nullable()->change();
            $table->timestamp('sandbox_refresh_expires_at')->nullable()->change();
        });
    }
};
