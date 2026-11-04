<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ch_ventacart_listings';

    private const COLUMNS = ['live_price', 'live_quantity'];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        $drop = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn(self::TABLE, $c)));
        if ($drop !== []) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn($drop));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        Schema::table(self::TABLE, function (Blueprint $t) {
            if (! Schema::hasColumn(self::TABLE, 'live_price')) {
                $t->decimal('live_price', 12, 2)->nullable()->after('live_checked_at');
            }
            if (! Schema::hasColumn(self::TABLE, 'live_quantity')) {
                $t->integer('live_quantity')->nullable()->after('live_price');
            }
        });
    }
};
