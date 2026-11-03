<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ch_ventacart_product_groups';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, 'listing_template_id')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn('listing_template_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && ! Schema::hasColumn(self::TABLE, 'listing_template_id')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->unsignedBigInteger('listing_template_id')->nullable()->after('name'));
        }
    }
};
