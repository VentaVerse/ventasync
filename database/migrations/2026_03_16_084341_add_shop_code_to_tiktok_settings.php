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
            if (!Schema::hasColumn('tiktok_settings', 'shop_code')) {
                $table->string('shop_code', 64)->nullable()->after('shop_cipher');
            }
            if (!Schema::hasColumn('tiktok_settings', 'sandbox_shop_code')) {
                $table->string('sandbox_shop_code', 64)->nullable()->after('sandbox_shop_cipher');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_settings', function (Blueprint $table) {
            $table->dropColumn(['shop_code', 'sandbox_shop_code']);
        });
    }
};
