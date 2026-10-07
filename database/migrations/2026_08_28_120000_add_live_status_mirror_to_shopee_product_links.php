<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_product_links', function (Blueprint $table) {
            $table->string('live_status', 24)->nullable()->after('sku');
            $table->timestamp('live_checked_at')->nullable()->after('live_status');
            $table->index('live_status');
        });

        if (!Schema::hasTable('scheduled_jobs')) {
            return;
        }
        $hasShopeeJobs = DB::table('scheduled_jobs')->where('integration', 'shopee')->exists();
        $exists = DB::table('scheduled_jobs')->where('command', 'shopee:refresh-listing-status')->exists();
        if ($hasShopeeJobs && !$exists) {
            DB::table('scheduled_jobs')->insert([
                'command' => 'shopee:refresh-listing-status', 'display_name' => 'Refresh Listing Status',
                'integration' => 'shopee', 'store_id' => null,
                'cadence_value' => 30, 'cadence_unit' => 'minute', 'cron_expression' => '*/30 * * * *',
                'enabled' => true, 'options' => json_encode([]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shopee_product_links', function (Blueprint $table) {
            $table->dropIndex(['live_status']);
            $table->dropColumn(['live_status', 'live_checked_at']);
        });
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')->where('command', 'shopee:refresh-listing-status')->delete();
        }
    }
};
