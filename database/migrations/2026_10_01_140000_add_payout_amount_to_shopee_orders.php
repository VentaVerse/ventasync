<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shopee_orders', 'payout_amount')) {
            return;
        }

        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->decimal('payout_amount', 12, 2)->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('shopee_orders', 'payout_amount')) {
            Schema::table('shopee_orders', function (Blueprint $table) {
                $table->dropColumn('payout_amount');
            });
        }
    }
};
