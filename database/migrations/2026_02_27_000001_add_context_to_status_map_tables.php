<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopee_order_status_map', function (Blueprint $table) {
            $table->string('context', 16)->default('order')->after('shopee_status');
        });

        DB::table('shopee_order_status_map')->whereNull('context')->orWhere('context', '')->update(['context' => 'order']);

        DB::table('shopee_order_status_map')
            ->whereIn('shopee_status', ['REQUESTED', 'ACCEPTED', 'JUDGING', 'SELLER_DISPUTE', 'REFUND_PAID', 'CLOSED', 'SELLER_COMPENSATION', 'PROCESSING'])
            ->where('context', 'order')
            ->update(['context' => 'return']);

        Schema::table('shopee_order_status_map', function (Blueprint $table) {
            $table->dropUnique(['shopee_status']);
            $table->unique(['shopee_status', 'context']);
        });

        Schema::table('lazada_order_status_map', function (Blueprint $table) {
            $table->string('context', 16)->default('order')->after('lazada_status');
        });

        DB::table('lazada_order_status_map')->whereNull('context')->orWhere('context', '')->update(['context' => 'order']);

        DB::table('lazada_order_status_map')
            ->whereIn('lazada_status', ['returned', 'return_initiated', 'in_progress', 'processing', 'approved', 'shipped_back', 'received', 'dispute_in_progress', 'refund_paid', 'closed', 'rejected'])
            ->where('context', 'order')
            ->update(['context' => 'return']);

        Schema::table('lazada_order_status_map', function (Blueprint $table) {
            $table->dropUnique(['lazada_status']);
            $table->unique(['lazada_status', 'context']);
        });
    }

    public function down(): void
    {
        DB::table('shopee_order_status_map')->where('context', 'return')->delete();

        Schema::table('shopee_order_status_map', function (Blueprint $table) {
            $table->dropUnique(['shopee_status', 'context']);
            $table->unique('shopee_status');
            $table->dropColumn('context');
        });

        DB::table('lazada_order_status_map')->where('context', 'return')->delete();

        Schema::table('lazada_order_status_map', function (Blueprint $table) {
            $table->dropUnique(['lazada_status', 'context']);
            $table->unique('lazada_status');
            $table->dropColumn('context');
        });
    }
};
