<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lazada_profiles', function (Blueprint $table) {
            // Make category optional (null = all products)
            $table->unsignedInteger('catalog_category_id')->nullable()->change();

            // Markup to cover Lazada fees
            $table->string('markup_type', 20)->nullable()->after('brand_name_override'); // 'fixed' or 'percent'
            $table->decimal('markup_value', 12, 2)->nullable()->after('markup_type');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->unsignedInteger('catalog_category_id')->nullable(false)->change();
            $table->dropColumn(['markup_type', 'markup_value']);
        });
    }
};
