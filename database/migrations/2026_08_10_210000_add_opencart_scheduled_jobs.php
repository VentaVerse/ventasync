<?php

use Extensions\opencart\Support\OpencartScheduledJobs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put OpenCart's three jobs on the ERP scheduler, the same as Venta's.
 *
 * Its Scheduling tab printed three artisan commands to paste into a hosting
 * panel by hand, so those jobs ran only if the operator had pasted each one
 * and the ERP knew nothing about whether they existed or when they last ran.
 * The command list and the cadences here are carried over verbatim from that
 * block, so nothing about WHICH jobs run or how often is decided by this
 * migration.
 *
 * Disabled on arrival: OpenCart stores point at real storefronts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('opencart_settings')) {
            return;
        }

        foreach (DB::table('opencart_settings')->orderBy('id')->get(['id']) as $store) {
            OpencartScheduledJobs::ensureFor((int) $store->id);
        }
    }

    public function down(): void
    {
        \App\Models\ScheduledJob::query()->where('integration', 'opencart')->delete();
    }
};
