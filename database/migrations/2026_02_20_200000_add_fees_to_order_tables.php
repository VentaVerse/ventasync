<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->json('fees')->nullable()->after('raw');
        });

        Schema::table('lazada_orders', function (Blueprint $table) {
            $table->json('fees')->nullable()->after('raw');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->dropColumn('fees');
        });

        Schema::table('lazada_orders', function (Blueprint $table) {
            $table->dropColumn('fees');
        });
    }
};
