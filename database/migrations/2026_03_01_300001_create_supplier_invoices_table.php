<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 32);
            $table->string('supplier_ref', 180)->nullable();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('status', 24)->default('draft');
            $table->string('currency_code', 3);
            $table->decimal('exchange_rate', 15, 8)->default(1);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('amount_paid', 15, 4)->default(0);
            $table->string('payment_status', 16)->default('unpaid');
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->integer('product_id')->nullable();
            $table->string('description', 255);
            $table->string('sku', 128)->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('supplier_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->decimal('amount', 15, 4);
            $table->string('payment_method', 64);
            $table->date('payment_date');
            $table->string('reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_payments');
        Schema::dropIfExists('supplier_invoice_items');
        Schema::dropIfExists('supplier_invoices');
    }
};
