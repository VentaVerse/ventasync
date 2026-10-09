<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('nickname', 64)->nullable();
            $table->string('contact_person', 128)->nullable();
            $table->string('email', 128)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 128)->nullable();
            $table->string('country', 128)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('currency_code', 3)->default('PHP');
            $table->string('payment_terms', 64)->nullable();
            $table->text('notes')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();
            $table->integer('product_id');
            $table->integer('product_variation_value_id')->nullable();
            $table->unsignedBigInteger('supplier_id');
            $table->string('supplier_sku', 128)->nullable();
            $table->mediumText('supplier_description')->nullable();
            $table->integer('min_qty')->default(1);
            $table->string('currency_code', 3)->default('PHP');
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['product_id', 'supplier_id', 'product_variation_value_id'], 'product_suppliers_unique');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 16)->nullable();
            $table->unsignedBigInteger('supplier_id');
            $table->string('status', 24)->default('draft');
            $table->string('currency_code', 3);
            $table->decimal('exchange_rate', 15, 8)->default(1);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('charges_amount', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->string('payment_status', 16)->default('unpaid');
            $table->decimal('amount_paid', 15, 4)->default(0);
            $table->date('payment_due_date')->nullable();
            $table->string('supplier_ref', 128)->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('last_emailed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unique(['po_number'], 'purchase_orders_po_number_unique');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->integer('product_id')->nullable();
            $table->integer('product_variation_value_id')->nullable();
            $table->string('description', 255);
            $table->string('sku', 128)->nullable();
            $table->string('supplier_sku', 128)->nullable();
            $table->mediumText('supplier_description')->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('qty_received')->default(0);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->enum('discount_type', ['percent', 'fixed'])->default('percent');
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('cost_applied', 15, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
        });

        Schema::create('purchase_order_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->string('name', 160);
            $table->decimal('amount', 15, 4)->default(0);
            $table->boolean('add_to_cost')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('purchase_order_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->decimal('amount', 15, 4);
            $table->string('payment_method', 64);
            $table->date('payment_date');
            $table->string('reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('purchase_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->string('filename', 255);
            $table->string('original_name', 255);
            $table->unsignedInteger('file_size')->default(0);
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('purchase_order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->string('action', 32);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->foreign('supplier_id', 'product_suppliers_supplier_id_foreign')->references('id')->on('suppliers')->onDelete('cascade');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreign('approved_by', 'purchase_orders_approved_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by', 'purchase_orders_created_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('supplier_id', 'purchase_orders_supplier_id_foreign')->references('id')->on('suppliers');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreign('purchase_order_id', 'purchase_order_items_purchase_order_id_foreign')->references('id')->on('purchase_orders')->onDelete('cascade');
        });

        Schema::table('purchase_order_charges', function (Blueprint $table) {
            $table->foreign('purchase_order_id', 'purchase_order_charges_purchase_order_id_foreign')->references('id')->on('purchase_orders')->onDelete('cascade');
        });

        Schema::table('purchase_order_payments', function (Blueprint $table) {
            $table->foreign('purchase_order_id', 'purchase_order_payments_purchase_order_id_foreign')->references('id')->on('purchase_orders')->onDelete('cascade');
        });

        Schema::table('purchase_order_attachments', function (Blueprint $table) {
            $table->foreign('purchase_order_id', 'purchase_order_attachments_purchase_order_id_foreign')->references('id')->on('purchase_orders')->onDelete('cascade');
        });

        Schema::table('purchase_order_logs', function (Blueprint $table) {
            $table->foreign('purchase_order_id', 'purchase_order_logs_purchase_order_id_foreign')->references('id')->on('purchase_orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('purchase_order_logs');
        Schema::dropIfExists('purchase_order_attachments');
        Schema::dropIfExists('purchase_order_payments');
        Schema::dropIfExists('purchase_order_charges');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('product_suppliers');
        Schema::dropIfExists('suppliers');
        Schema::enableForeignKeyConstraints();
    }
};
