<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('catalog.prefix') . 'product';

        if (!Schema::hasColumn($table, 'reorder_level')) {
            Schema::table($table, function (Blueprint $t) {
                $t->integer('reorder_level')->default(0)->after('quantity');
            });
        }
    }

    public function down(): void
    {
        $table = config('catalog.prefix') . 'product';

        if (Schema::hasColumn($table, 'reorder_level')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('reorder_level');
            });
        }
    }
};
