<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasTable('venta_settings')) {
            return;
        }

        foreach (DB::table('venta_settings')->pluck('id') as $storeId) {
            $exists = DB::table('scheduled_jobs')
                ->where('integration', 'venta')
                ->where('store_id', (int) $storeId)
                ->where('command', 'venta:refresh-listing-status')
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('scheduled_jobs')->insert([
                'integration' => 'venta', 'store_id' => (int) $storeId,
                'display_name' => 'Refresh Listing Status', 'command' => 'venta:refresh-listing-status',
                'cadence_value' => 30, 'cadence_unit' => 'minute', 'cron_expression' => '*/30 * * * *',
                'enabled' => false, 'options' => json_encode(['store' => (int) $storeId]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')->where('integration', 'venta')->where('command', 'venta:refresh-listing-status')->delete();
        }
    }
};
