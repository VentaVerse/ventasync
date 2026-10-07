<?php

use App\Models\ScheduledJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('venta_settings')) {
            return;
        }

        foreach (DB::table('venta_settings')->orderBy('id')->get(['id']) as $store) {
            \Extensions\venta\Support\VentaScheduledJobs::ensureFor((int) $store->id);
        }
    }

    public function down(): void
    {
        ScheduledJob::query()->where('integration', 'venta')->delete();
    }
};
