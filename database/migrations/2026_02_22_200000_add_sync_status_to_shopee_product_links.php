<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_product_links', function (Blueprint $table) {
            $table->timestamp('last_synced_at')->nullable()->after('sku');
            $table->string('last_sync_action', 50)->nullable()->after('last_synced_at');
            $table->boolean('last_sync_ok')->nullable()->after('last_sync_action');
            $table->string('last_sync_error_code', 80)->nullable()->after('last_sync_ok');
            $table->string('last_sync_error_message', 255)->nullable()->after('last_sync_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_product_links', function (Blueprint $table) {
            $table->dropColumn([
                'last_synced_at',
                'last_sync_action',
                'last_sync_ok',
                'last_sync_error_code',
                'last_sync_error_message',
            ]);
        });
    }
};
