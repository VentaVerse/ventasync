<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate vendor SKU from ERP internal SKU.
     * - sku: the ERP/internal product SKU (read-only reference)
     * - vendor_sku: the supplier's part number (editable, goes on PO sent to vendor)
     */
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('vendor_sku', 128)->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('vendor_sku');
        });
    }
};
