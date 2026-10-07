<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopee_orders', function (Blueprint $table) {
            $table->id();
            $table->string('region', 8)->index();
            $table->string('order_sn', 64)->index();
            $table->string('status', 64)->nullable()->index();
            $table->timestamp('order_created_at')->nullable()->index();
            $table->timestamp('order_updated_at')->nullable()->index();
            $table->json('raw')->nullable();
            $table->unsignedInteger('catalog_order_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['region', 'order_sn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_orders');
    }
};
