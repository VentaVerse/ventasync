<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('internal_notes')->nullable()->after('notes');
            $table->decimal('charges_amount', 15, 4)->default(0)->after('discount_amount');
        });

        Schema::create('purchase_order_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('name', 160);
            $table->decimal('amount', 15, 4)->default(0);
            $table->boolean('add_to_cost')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->mediumText('supplier_description')->nullable()->after('vendor_sku');
            $table->decimal('cost_applied', 15, 4)->nullable()->after('total');
        });

        Schema::table('product_vendors', function (Blueprint $table) {
            $table->mediumText('supplier_description')->nullable()->after('vendor_sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_vendors', function (Blueprint $table) {
            $table->dropColumn('supplier_description');
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['supplier_description', 'cost_applied']);
        });
        Schema::dropIfExists('purchase_order_charges');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['internal_notes', 'charges_amount']);
        });
    }
};
