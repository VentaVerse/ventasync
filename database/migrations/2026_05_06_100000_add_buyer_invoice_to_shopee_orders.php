<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            // Generic name (not "shopee_buyer_invoice") so a future
            // marketplace-agnostic UI can reuse the same shape.
            $table->json('buyer_invoice')->nullable()->after('raw');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->dropColumn('buyer_invoice');
        });
    }
};
