<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sync Payouts is its own automation (the owner, 2026-10-01).
 *
 * Shopee, Lazada and TikTok Shop checked their payouts inside Sync Orders,
 * every minute: Shopee re-read the same 14 days of wallet 5,760 times a day
 * and never saw anything older. The order syncs no longer do it; a "Sync
 * Payouts" job beside each Sync Orders job does instead: same integration,
 * same store, hourly, looking back 90 days, edited on the Automations tab.
 *
 * It is switched on wherever that store's Sync Orders is on, because it
 * replaces a step Sync Orders was running. The AutomationCatalogue may have
 * created the row already, switched off as every new automation arrives,
 * on the first boot that saw the declaration; that row is switched on here
 * rather than left off, so payouts are not silently dropped. Running it
 * again changes nothing.
 */
return new class extends Migration
{
    /** integration => [orders command, payouts command] */
    private const PAIRS = [
        'shopee' => ['shopee:sync-orders', 'shopee:sync-payouts'],
        'lazada' => ['lazada:sync-orders', 'lazada:sync-payouts'],
        'tiktok' => ['tiktok:sync-orders', 'tiktok:sync-payouts'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        foreach (self::PAIRS as $integration => [$orders, $payouts]) {
            // The Sync Orders rows (not Sync Returns): those ran the payout step.
            $orderRows = DB::table('scheduled_jobs')
                ->where('integration', $integration)->where('command', $orders)
                ->where(fn ($q) => $q->whereNull('options')->orWhere('options', 'not like', '%"returns":true%'))
                ->get(['store_id', 'enabled']);

            foreach ($orderRows->groupBy(fn ($r) => (string) $r->store_id) as $rows) {
                $storeId = $rows->first()->store_id;
                $on = $rows->contains(fn ($r) => (bool) $r->enabled);
                $sameStore = fn ($q) => $storeId === null ? $q->whereNull('store_id') : $q->where('store_id', $storeId);

                $existing = DB::table('scheduled_jobs')
                    ->where('integration', $integration)->where('command', $payouts)->where($sameStore)->first();
                if ($existing) {
                    if ($on && ! $existing->enabled) {
                        DB::table('scheduled_jobs')->where('id', $existing->id)->update(['enabled' => true, 'updated_at' => now()]);
                    }

                    continue;
                }
                DB::table('scheduled_jobs')->insert([
                    'integration' => $integration, 'store_id' => $storeId,
                    'display_name' => 'Sync Payouts', 'command' => $payouts,
                    'cadence_value' => 1, 'cadence_unit' => 'hour', 'cron_expression' => '0 * * * *',
                    'enabled' => $on, 'options' => json_encode(['days' => 90]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }
        foreach (self::PAIRS as $integration => [, $payouts]) {
            DB::table('scheduled_jobs')->where('integration', $integration)->where('command', $payouts)->delete();
        }
    }
};
