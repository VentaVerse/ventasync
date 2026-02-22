<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->timestamp('last_return_sync_at')->nullable()->after('last_stock_push_at');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->dropColumn('last_return_sync_at');
        });
    }
};
