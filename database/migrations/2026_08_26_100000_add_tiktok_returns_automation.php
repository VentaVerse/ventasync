<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tiktok_settings') && !Schema::hasColumn('tiktok_settings', 'sync_last_days_returns')) {
            Schema::table('tiktok_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('sync_last_days_returns')->nullable()->after('sync_last_days');
            });
        }

        if (!Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $orders = DB::table('scheduled_jobs')
            ->where('integration', 'tiktok')->whereNull('store_id')
            ->where('command', 'tiktok:sync-orders')->where('display_name', 'Sync Orders')
            ->first();
        if (!$orders) {
            return;
        }

        $options = json_decode((string) ($orders->options ?? ''), true) ?: [];
        $options['no_returns'] = true;
        DB::table('scheduled_jobs')->where('id', $orders->id)->update(['options' => json_encode($options), 'updated_at' => now()]);

        $exists = DB::table('scheduled_jobs')
            ->where('integration', 'tiktok')->whereNull('store_id')
            ->where('command', 'tiktok:sync-orders')->where('display_name', 'Sync Returns')
            ->exists();
        if (!$exists) {
            DB::table('scheduled_jobs')->insert([
                'command' => 'tiktok:sync-orders', 'display_name' => 'Sync Returns', 'integration' => 'tiktok', 'store_id' => null,
                'cadence_value' => 30, 'cadence_unit' => 'minute', 'cron_expression' => '*/30 * * * *',
                'enabled' => true, 'options' => json_encode(['returns' => true]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')->where('integration', 'tiktok')->where('display_name', 'Sync Returns')->delete();
        }
        if (Schema::hasTable('tiktok_settings') && Schema::hasColumn('tiktok_settings', 'sync_last_days_returns')) {
            Schema::table('tiktok_settings', function (Blueprint $table) {
                $table->dropColumn('sync_last_days_returns');
            });
        }
    }
};
