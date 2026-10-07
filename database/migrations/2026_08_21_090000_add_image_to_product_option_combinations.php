<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->string('image', 255)->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
