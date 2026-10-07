<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lazada_reverse_orders', function (Blueprint $table) {
            $table->id();
            $table->string('region', 10)->index();
            $table->string('reverse_order_id')->index();
            $table->string('trade_order_id')->nullable()->index();
            $table->string('reverse_status')->nullable()->index();
            $table->string('reverse_type')->nullable();
            $table->string('reason')->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->json('items')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['region', 'reverse_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_reverse_orders');
    }
};
