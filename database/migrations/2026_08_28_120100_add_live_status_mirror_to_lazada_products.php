<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Lazada listing status MIRROR (the owner, 2026-08-28), the same shape as
 * Shopee's: the marketplace's own status kept on the listing row,
 * refreshed every 30 minutes by the "Refresh Listing Status" automation
 * seeded below, on demand from the page, and written through by every
 * activate and deactivate the ERP makes. A page view asks Lazada for
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            // Lazada's own status word: active, inactive, pending, rejected,
            // sold-out, deleted, or missing when the shop no longer returns
            // the item. Null until the first refresh.
            $table->string('live_status', 24)->nullable()->after('lazada_item_id');
            $table->timestamp('live_checked_at')->nullable()->after('live_status');
            $table->index('live_status');
        });

        if (!Schema::hasTable('scheduled_jobs')) {
            return;
        }
        $hasLazadaJobs = DB::table('scheduled_jobs')->where('integration', 'lazada')->exists();
        $exists = DB::table('scheduled_jobs')->where('command', 'lazada:refresh-listing-status')->exists();
        if ($hasLazadaJobs && !$exists) {
            DB::table('scheduled_jobs')->insert([
                'command' => 'lazada:refresh-listing-status', 'display_name' => 'Refresh Listing Status',
                'integration' => 'lazada', 'store_id' => null,
                'cadence_value' => 30, 'cadence_unit' => 'minute', 'cron_expression' => '*/30 * * * *',
                'enabled' => true, 'options' => json_encode([]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->dropIndex(['live_status']);
            $table->dropColumn(['live_status', 'live_checked_at']);
        });
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')->where('command', 'lazada:refresh-listing-status')->delete();
        }
    }
};
