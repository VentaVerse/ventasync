<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            if (! Schema::hasColumn('shopee_listings', 'image_order')) {
                $table->json('image_order')->nullable()->after('attribute_values');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            if (Schema::hasColumn('shopee_listings', 'image_order')) {
                $table->dropColumn('image_order');
            }
        });
    }
};
