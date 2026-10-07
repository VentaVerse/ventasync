<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_history', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('stock_history', function (Blueprint $table) {
            $table->dropColumn('warehouse_id');
        });
    }
};
