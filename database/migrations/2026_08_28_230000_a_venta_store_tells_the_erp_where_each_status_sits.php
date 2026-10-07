<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('venta_status_placements')) {
            return;
        }

        Schema::create('venta_status_placements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('venta_setting_id');
            $t->unsignedInteger('venta_status_id');
            $t->string('venta_status_name', 64)->default('');
            $t->string('placement', 32)->nullable();
            $t->timestamp('fetched_at')->nullable();
            $t->timestamps();

            $t->unique(['venta_setting_id', 'venta_status_id'], 'venta_status_placement_unique');
            $t->foreign('venta_setting_id')->references('id')->on('venta_settings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_status_placements');
    }
};
