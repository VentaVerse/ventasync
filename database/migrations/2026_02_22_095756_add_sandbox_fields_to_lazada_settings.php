<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->string('mode', 10)->default('live')->after('id');

            // Sandbox credentials (mirroring the live fields)
            $table->string('sandbox_app_key', 64)->nullable()->after('api_logging');
            $table->text('sandbox_app_secret')->nullable()->after('sandbox_app_key');
            $table->string('sandbox_redirect_uri', 255)->nullable()->after('sandbox_app_secret');
            $table->text('sandbox_auth_code')->nullable()->after('sandbox_redirect_uri');
            $table->text('sandbox_access_token')->nullable()->after('sandbox_auth_code');
            $table->text('sandbox_refresh_token')->nullable()->after('sandbox_access_token');
            $table->datetime('sandbox_expires_at')->nullable()->after('sandbox_refresh_token');
            $table->datetime('sandbox_refresh_expires_at')->nullable()->after('sandbox_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->dropColumn([
                'mode',
                'sandbox_app_key',
                'sandbox_app_secret',
                'sandbox_redirect_uri',
                'sandbox_auth_code',
                'sandbox_access_token',
                'sandbox_refresh_token',
                'sandbox_expires_at',
                'sandbox_refresh_expires_at',
            ]);
        });
    }
};
