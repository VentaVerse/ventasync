<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedallion_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->string('pedallion_status', 64);
            $table->string('context', 16)->default('order');
            $table->unsignedInteger('order_status_id');
            $table->timestamps();

            $table->unique(['pedallion_status', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedallion_order_status_map');
    }
};
