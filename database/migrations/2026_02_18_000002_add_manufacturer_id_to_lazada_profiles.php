<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('lazada_profiles', 'manufacturer_id')) {
            return;
        }

        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->unsignedInteger('manufacturer_id')->nullable()->after('catalog_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->dropColumn('manufacturer_id');
        });
    }
};
