<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pedallion_order_status_map', function (Blueprint $table) {
            $table->string('pedallion_status_label', 100)->nullable()->after('pedallion_status');
        });
    }

    public function down(): void
    {
        Schema::table('pedallion_order_status_map', function (Blueprint $table) {
            $table->dropColumn('pedallion_status_label');
        });
    }
};
