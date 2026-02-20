<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sync_last_days')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->dropColumn('sync_last_days');
        });
    }
};
