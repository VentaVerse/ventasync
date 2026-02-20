<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('catalog.prefix');
        $table = $p.'order_status';

        if (!Schema::hasColumn($table, 'add_revenue')) {
            Schema::table($table, function (Blueprint $t) {
                $t->tinyInteger('add_revenue')->default(0)->after('subtract_stock');
            });
        }

        // Seed: Delivered (9) and Completed (10) add to revenue by default
        DB::table($table)->whereIn('order_status_id', [9, 10])->update(['add_revenue' => 1]);
    }

    public function down(): void
    {
        $p = config('catalog.prefix');
        $table = $p.'order_status';

        if (Schema::hasColumn($table, 'add_revenue')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('add_revenue');
            });
        }
    }
};
