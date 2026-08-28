<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The TikTok Shop listing status MIRROR (the owner, 2026-08-28), the same
 * shape as Shopee's: the listings page used to walk the whole shop on
 * every page load to count its tabs and chip its rows. The status now
 * lives on the listing row, refreshed by the "Refresh Listing Status"
 * automation seeded below (every 30 minutes), on demand from the page,
 * and written through by every activate and deactivate the ERP makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_listings', function (Blueprint $table) {
            // TikTok's own status word (ACTIVATE, SELLER_DEACTIVATED,
            // PLATFORM_DEACTIVATED, IN_REVIEW, FAILED, FREEZE, ...) or
            // MISSING when the shop no longer returns the product. Null
            // until the first refresh.
            $table->string('live_status', 32)->nullable()->after('tiktok_sku_id');
            $table->timestamp('live_checked_at')->nullable()->after('live_status');
            $table->index('live_status');
        });

        if (!Schema::hasTable('scheduled_jobs')) {
            return;
        }
        $hasTiktokJobs = DB::table('scheduled_jobs')->where('integration', 'tiktok')->exists();
        $exists = DB::table('scheduled_jobs')->where('command', 'tiktok:refresh-listing-status')->exists();
        if ($hasTiktokJobs && !$exists) {
            DB::table('scheduled_jobs')->insert([
                'command' => 'tiktok:refresh-listing-status', 'display_name' => 'Refresh Listing Status',
                'integration' => 'tiktok', 'store_id' => null,
                'cadence_value' => 30, 'cadence_unit' => 'minute', 'cron_expression' => '*/30 * * * *',
                'enabled' => true, 'options' => json_encode([]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('tiktok_listings', function (Blueprint $table) {
            $table->dropIndex(['live_status']);
            $table->dropColumn(['live_status', 'live_checked_at']);
        });
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')->where('command', 'tiktok:refresh-listing-status')->delete();
        }
    }
};
