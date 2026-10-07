<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('catalog.prefix') . 'order';
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'tracking_url')) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('tracking_url', 500)->nullable()->after('tracking_number');
            });
        }
    }

    public function down(): void
    {
        $table = config('catalog.prefix') . 'order';
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'tracking_url')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('tracking_url');
            });
        }
    }
};
