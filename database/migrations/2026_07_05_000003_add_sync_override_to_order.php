<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-order manual Override. When set, marketplace syncs must NOT change
     * the order's status or move stock — the operator has taken manual control
     * (e.g. finalising a return as Returned/Refunded). See order-status memo.
     */
    public function up(): void
    {
        $table = config('catalog.prefix') . 'order';
        if (Schema::hasTable($table) && !Schema::hasColumn($table, 'sync_override')) {
            Schema::table($table, function (Blueprint $t) {
                $t->tinyInteger('sync_override')->default(0)->after('order_status_id');
            });
        }
    }

    public function down(): void
    {
        $table = config('catalog.prefix') . 'order';
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'sync_override')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('sync_override');
            });
        }
    }
};
