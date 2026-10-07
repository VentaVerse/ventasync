<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('catalog.prefix');

        if (Schema::hasTable($p.'order') && !Schema::hasColumn($p.'order', 'foreign_total')) {
            Schema::table($p.'order', function (Blueprint $t) {
                $t->decimal('foreign_total', 15, 4)->nullable()->after('total');
            });
        }

        if (Schema::hasTable($p.'order_product')) {
            Schema::table($p.'order_product', function (Blueprint $t) use ($p) {
                if (!Schema::hasColumn($p.'order_product', 'foreign_price')) {
                    $t->decimal('foreign_price', 15, 4)->nullable()->after('price');
                }
                if (!Schema::hasColumn($p.'order_product', 'foreign_total')) {
                    $t->decimal('foreign_total', 15, 4)->nullable()->after('total');
                }
            });
        }
    }

    public function down(): void
    {
        $p = config('catalog.prefix');

        if (Schema::hasTable($p.'order') && Schema::hasColumn($p.'order', 'foreign_total')) {
            Schema::table($p.'order', fn (Blueprint $t) => $t->dropColumn('foreign_total'));
        }

        if (Schema::hasTable($p.'order_product')) {
            Schema::table($p.'order_product', function (Blueprint $t) use ($p) {
                $drop = array_values(array_filter(
                    ['foreign_price', 'foreign_total'],
                    fn ($c) => Schema::hasColumn($p.'order_product', $c)
                ));
                if ($drop) {
                    $t->dropColumn($drop);
                }
            });
        }
    }
};
