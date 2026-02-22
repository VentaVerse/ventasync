<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('sandbox_partner_id')->nullable()->after('region');
            $table->text('sandbox_partner_key')->nullable()->after('sandbox_partner_id');
            $table->unsignedBigInteger('sandbox_shop_id')->nullable()->after('sandbox_partner_key');
            $table->text('sandbox_access_token')->nullable()->after('sandbox_shop_id');
            $table->text('sandbox_refresh_token')->nullable()->after('sandbox_access_token');
            $table->timestamp('sandbox_expires_at')->nullable()->after('sandbox_refresh_token');
            $table->timestamp('sandbox_refresh_expires_at')->nullable()->after('sandbox_expires_at');
            $table->string('sandbox_redirect_uri', 255)->nullable()->after('sandbox_refresh_expires_at');
            $table->string('sandbox_region', 16)->nullable()->after('sandbox_redirect_uri');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sandbox_partner_id',
                'sandbox_partner_key',
                'sandbox_shop_id',
                'sandbox_access_token',
                'sandbox_refresh_token',
                'sandbox_expires_at',
                'sandbox_refresh_expires_at',
                'sandbox_redirect_uri',
                'sandbox_region',
            ]);
        });
    }
};
