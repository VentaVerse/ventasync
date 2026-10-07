<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->decimal('cost_additional', 15, 4)->default(0)->after('cost_amount');
        });

        $product = config('catalog.prefix') . 'product';
        DB::statement("UPDATE product_option_combinations c INNER JOIN `{$product}` p ON p.product_id = c.product_id SET c.cost_additional = p.cost_additional");
    }

    public function down(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->dropColumn('cost_additional');
        });
    }
};
