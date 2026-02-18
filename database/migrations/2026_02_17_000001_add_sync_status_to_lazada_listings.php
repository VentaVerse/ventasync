<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            if (!Schema::hasColumn('lazada_products', 'last_sync_action')) {
                $table->string('last_sync_action', 50)->nullable()->after('last_synced_at');
            }
            if (!Schema::hasColumn('lazada_products', 'last_sync_ok')) {
                $table->boolean('last_sync_ok')->nullable()->after('last_sync_action');
            }
            if (!Schema::hasColumn('lazada_products', 'last_sync_error_code')) {
                $table->string('last_sync_error_code', 80)->nullable()->after('last_sync_ok');
            }
            if (!Schema::hasColumn('lazada_products', 'last_sync_error_message')) {
                $table->string('last_sync_error_message', 255)->nullable()->after('last_sync_error_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            if (Schema::hasColumn('lazada_products', 'last_sync_error_message')) {
                $table->dropColumn('last_sync_error_message');
            }
            if (Schema::hasColumn('lazada_products', 'last_sync_error_code')) {
                $table->dropColumn('last_sync_error_code');
            }
            if (Schema::hasColumn('lazada_products', 'last_sync_ok')) {
                $table->dropColumn('last_sync_ok');
            }
            if (Schema::hasColumn('lazada_products', 'last_sync_action')) {
                $table->dropColumn('last_sync_action');
            }
        });
    }
};
