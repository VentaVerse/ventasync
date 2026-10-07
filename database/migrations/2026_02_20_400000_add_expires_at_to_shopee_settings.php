<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('refresh_token');
            $table->timestamp('refresh_expires_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'refresh_expires_at']);
        });
    }
};
