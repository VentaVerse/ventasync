<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lazada_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->string('lazada_status', 64)->unique();
            $table->unsignedInteger('order_status_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_order_status_map');
    }
};
