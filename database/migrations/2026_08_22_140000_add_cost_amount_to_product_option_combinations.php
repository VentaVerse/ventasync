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
            $table->decimal('cost_amount', 15, 4)->default(0)->after('absolute_cost');
        });

        DB::statement('UPDATE product_option_combinations SET cost_amount = absolute_cost');
    }

    public function down(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->dropColumn('cost_amount');
        });
    }
};
