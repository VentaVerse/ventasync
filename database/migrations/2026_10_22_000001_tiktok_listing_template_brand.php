<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ch_tiktok_listing_templates', function (Blueprint $table) {
            $table->string('brand_id', 64)->nullable()->after('tiktok_category_id');
            $table->string('brand_name', 255)->nullable()->after('brand_id');
        });
    }

    public function down(): void
    {
        Schema::table('ch_tiktok_listing_templates', function (Blueprint $table) {
            $table->dropColumn(['brand_id', 'brand_name']);
        });
    }
};
