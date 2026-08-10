<?php

use App\Models\ScheduledJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put Venta's three jobs on the same scheduler as everyone else's.
 *
 * Shopee, Lazada and TikTok each keep their jobs as scheduled_jobs rows, which
 * App\Console\Kernel registers with Laravel's scheduler, so one OS cron entry
 * (`* * * * * php artisan schedule:run`) drives all of them and the Automations
 * tab edits cadence, options and on/off without anyone touching a crontab.
 *
 * Venta had none. Its Schedule tab printed three commands to copy into the
 * hosting panel by hand, so those three jobs ran only if the operator had
 * pasted each one, and the ERP had no idea whether they existed, when they
 * last ran, or whether they had failed.
 *
 * One set per store, because Venta is multi-store and every store is a
 * separate live storefront: the --store option is carried in options so the
 * Kernel passes it on the command line, and store_id scopes the row to the
 * settings page that shows it.
 *
 * The cadences are the ones Venta's own tab recommended in prose.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('venta_settings')) {
            return;
        }

        // The job list lives in VentaScheduledJobs, which the store-create
        // path also calls, so a store added tomorrow gets exactly what a store
        // that existed today got.
        foreach (DB::table('venta_settings')->orderBy('id')->get(['id']) as $store) {
            \Extensions\venta\Support\VentaScheduledJobs::ensureFor((int) $store->id);
        }
    }

    public function down(): void
    {
        ScheduledJob::query()->where('integration', 'venta')->delete();
    }
};
