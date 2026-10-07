<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->json('attribute_values')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->dropColumn('attribute_values');
        });
    }
};
