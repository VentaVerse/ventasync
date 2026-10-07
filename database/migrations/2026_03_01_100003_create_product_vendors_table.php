<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_vendors', function (Blueprint $table) {
            $table->id();
            $table->integer('product_id');
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('vendor_sku', 128)->nullable();
            $table->integer('min_qty')->default(1);
            $table->string('currency_code', 3)->default('PHP');
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_vendors');
    }
};
