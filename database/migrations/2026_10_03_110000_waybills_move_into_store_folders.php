<?php

use App\Support\Fulfilment\Waybills;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANNELS = [
        'shopee' => ['shopee_orders', 'order_sn', 'shopee_setting_id'],
        'lazada' => ['lazada_orders', 'order_id', 'lazada_setting_id'],
        'tiktok' => ['tiktok_orders', 'order_id', 'tiktok_setting_id'],
    ];

    public function up(): void
    {
        foreach (self::CHANNELS as $channel => [$table, $column, $storeColumn]) {
            $dir = storage_path('app/' . $channel . '-awb');
            if (! is_dir($dir) || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $storeColumn)) {
                continue;
            }

            foreach (glob($dir . '/*.pdf') ?: [] as $file) {
                $reference = basename($file, '.pdf');
                $stores = DB::table($table)->where($column, $reference)->distinct()->pluck($storeColumn);
                if ($stores->count() !== 1 || (int) $stores->first() <= 0) {
                    continue;
                }

                $target = Waybills::path($channel, (int) $stores->first(), $reference);
                if (is_file($target)) {
                    continue;
                }
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                rename($file, $target);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::CHANNELS) as $channel) {
            $dir = storage_path('app/' . $channel . '-awb');
            foreach (glob($dir . '/*/*.pdf') ?: [] as $file) {
                $flat = $dir . '/' . basename($file);
                if (! is_file($flat)) {
                    rename($file, $flat);
                }
            }
        }
    }
};
