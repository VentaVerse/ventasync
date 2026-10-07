<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shopee_returns')) {
            return;
        }

        Schema::table('shopee_returns', function (Blueprint $table) {
            if (!Schema::hasColumn('shopee_returns', 'needs_logistics')) {
                $table->boolean('needs_logistics')->nullable()->after('status');
            }
            if (!Schema::hasColumn('shopee_returns', 'return_solution')) {
                $table->tinyInteger('return_solution')->nullable()->after('needs_logistics');
            }
            if (!Schema::hasColumn('shopee_returns', 'reverse_logistics_status')) {
                $table->string('reverse_logistics_status', 64)->nullable()->after('return_solution');
            }
            if (!Schema::hasColumn('shopee_returns', 'is_arrived_at_warehouse')) {
                $table->boolean('is_arrived_at_warehouse')->nullable()->after('reverse_logistics_status');
            }
            if (!Schema::hasColumn('shopee_returns', 'seller_compensation_status')) {
                $table->string('seller_compensation_status', 64)->nullable()->after('is_arrived_at_warehouse');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shopee_returns')) {
            return;
        }

        Schema::table('shopee_returns', function (Blueprint $table) {
            foreach ([
                'needs_logistics',
                'return_solution',
                'reverse_logistics_status',
                'is_arrived_at_warehouse',
                'seller_compensation_status',
            ] as $col) {
                if (Schema::hasColumn('shopee_returns', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
