<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ch_lazada_listing_variations';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, 'quantity')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn('quantity'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && ! Schema::hasColumn(self::TABLE, 'quantity')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->integer('quantity')->nullable()->after('sku_id'));
        }
    }
};
