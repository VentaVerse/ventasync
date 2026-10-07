<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->string('payout_status', 20)->nullable()->after('fees')->index();
            $table->timestamp('paid_at')->nullable()->after('payout_status');
        });

        Schema::table('lazada_orders', function (Blueprint $table) {
            $table->string('payout_status', 20)->nullable()->after('fees')->index();
            $table->timestamp('paid_at')->nullable()->after('payout_status');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->dropColumn(['payout_status', 'paid_at']);
        });

        Schema::table('lazada_orders', function (Blueprint $table) {
            $table->dropColumn(['payout_status', 'paid_at']);
        });
    }
};
