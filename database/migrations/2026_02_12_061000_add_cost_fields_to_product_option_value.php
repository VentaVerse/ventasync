<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_option_value')) {
            return;
        }

        Schema::table('product_option_value', function (Blueprint $table) {
            // Add only if missing (idempotent)
            if (!Schema::hasColumn('product_option_value', 'sku')) {
                $table->string('sku', 64)->default('')->after('option_value_id');
            }
            if (!Schema::hasColumn('product_option_value', 'cost')) {
                $table->decimal('cost', 15, 4)->default(0.0000)->after('price');
            }
            if (!Schema::hasColumn('product_option_value', 'costing_method')) {
                $table->integer('costing_method')->default(0)->after('cost');
            }
            if (!Schema::hasColumn('product_option_value', 'cost_amount')) {
                $table->decimal('cost_amount', 15, 4)->default(0.0000)->after('costing_method');
            }
            if (!Schema::hasColumn('product_option_value', 'cost_prefix')) {
                $table->string('cost_prefix', 1)->default('+')->after('cost_amount');
            }
        });
    }

    public function down(): void
    {
        // No-op (safe). We do not drop columns automatically in production.
    }
};
