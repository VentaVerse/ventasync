<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Push Price is its own automation (the owner, 2026-09-07). "Push Stock &
 * Price" was one row on every channel, and on five of six its cron sent
 * stock only. The row is renamed to what it does, and a "Push Price" row
 * is created beside every one of them: same integration, same store, same
 * options, disabled, every thirty minutes until the operator says
 * otherwise. Idempotent: a price row that already exists is left alone.
 */
return new class extends Migration
{
    /** integration => [stock command, price command] */
    private const PAIRS = [
        'shopee' => ['shopee:push-stock', 'shopee:push-price'],
        'lazada' => ['lazada:push-stock', 'lazada:push-price'],
        'tiktok' => ['tiktok:push-stock', 'tiktok:push-price'],
        'venta' => ['venta:push-stock', 'venta:push-price'],
        'woocommerce' => ['woocommerce:push-stock', 'woocommerce:push-price'],
        'opencart' => ['opencart:push-qty', 'opencart:push-price'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        foreach (self::PAIRS as $integration => [$stock, $price]) {
            DB::table('scheduled_jobs')
                ->where('integration', $integration)->where('command', $stock)->where('display_name', 'Push Stock & Price')
                ->update(['display_name' => 'Push Stock', 'updated_at' => now()]);

            foreach (DB::table('scheduled_jobs')->where('integration', $integration)->where('command', $stock)->get() as $row) {
                $exists = DB::table('scheduled_jobs')
                    ->where('integration', $integration)->where('command', $price)
                    ->where(fn ($q) => $row->store_id === null ? $q->whereNull('store_id') : $q->where('store_id', $row->store_id))
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('scheduled_jobs')->insert([
                    'integration' => $integration, 'store_id' => $row->store_id,
                    'display_name' => 'Push Price', 'command' => $price,
                    'cadence_value' => 30, 'cadence_unit' => 'minute', 'cron_expression' => '*/30 * * * *',
                    'enabled' => false, 'options' => $row->options,
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
        foreach (self::PAIRS as $integration => [$stock, $price]) {
            DB::table('scheduled_jobs')->where('integration', $integration)->where('command', $price)->delete();
            DB::table('scheduled_jobs')
                ->where('integration', $integration)->where('command', $stock)->where('display_name', 'Push Stock')
                ->update(['display_name' => 'Push Stock & Price', 'updated_at' => now()]);
        }
    }
};
