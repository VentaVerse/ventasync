<?php

use Extensions\opencart\Support\OpencartScheduledJobs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
