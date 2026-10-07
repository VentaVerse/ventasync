<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
