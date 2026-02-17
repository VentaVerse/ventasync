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

        if (!Schema::hasColumn($table, 'subtract_stock')) {
            Schema::table($table, function (Blueprint $t) {
                $t->tinyInteger('subtract_stock')->default(0)->after('name');
            });
        }

        // Seed: Processing (2) and Delivered (9) subtract stock by default
        DB::table($table)->whereIn('order_status_id', [2, 9])->update(['subtract_stock' => 1]);
    }

    public function down(): void
    {
        $p = config('catalog.prefix');
        $table = $p.'order_status';

        if (Schema::hasColumn($table, 'subtract_stock')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('subtract_stock');
            });
        }
    }
};
