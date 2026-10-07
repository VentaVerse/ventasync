<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->dropColumn(['markup_type', 'markup_value']);
        });

        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->decimal('markup_fixed', 12, 2)->nullable()->after('brand_name_override');
            $table->decimal('markup_percent', 8, 2)->nullable()->after('markup_fixed');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->dropColumn(['markup_fixed', 'markup_percent']);
        });

        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->string('markup_type', 20)->nullable()->after('brand_name_override');
            $table->decimal('markup_value', 12, 2)->nullable()->after('markup_type');
        });
    }
};
