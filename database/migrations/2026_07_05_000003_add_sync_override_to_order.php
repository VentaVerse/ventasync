<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
