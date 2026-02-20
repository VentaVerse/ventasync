<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_profiles', function (Blueprint $table) {
            $table->json('logistic_ids')->nullable()->after('shopee_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_profiles', function (Blueprint $table) {
            $table->dropColumn('logistic_ids');
        });
    }
};
