<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedallion_product_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->string('pedallion_sku', 128);
            $table->string('sync_status', 32)->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'pedallion_sku']);
            $table->index('pedallion_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedallion_product_links');
    }
};
