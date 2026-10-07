<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('catalog.prefix') . 'product';

        if (!Schema::hasColumn($table, 'cost')) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('cost', 15, 4)->default(0)->after('price');
            });
        }
    }

    public function down(): void
    {
        $table = config('catalog.prefix') . 'product';

        if (Schema::hasColumn($table, 'cost')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('cost');
            });
        }
    }
};
