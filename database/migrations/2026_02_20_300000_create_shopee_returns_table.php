<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopee_returns', function (Blueprint $table) {
            $table->id();
            $table->string('region', 8)->index();
            $table->unsignedBigInteger('return_sn')->index();
            $table->string('order_sn', 64)->index();
            $table->unsignedBigInteger('shopee_order_id')->nullable()->index();
            $table->string('status', 64)->nullable()->index();
            $table->string('reason', 255)->nullable();
            $table->text('reason_text')->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->json('items')->nullable();
            $table->json('negotiation')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('return_created_at')->nullable();
            $table->timestamp('return_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['region', 'return_sn']);

            $table->foreign('shopee_order_id')
                ->references('id')
                ->on('shopee_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_returns');
    }
};
