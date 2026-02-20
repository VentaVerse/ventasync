<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('opencart_order_status_map')) {
            return;
        }

        Schema::create('opencart_order_status_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('opencart_setting_id');
            $table->unsignedInteger('oc_status_id');
            $table->string('oc_status_name', 64)->default('');
            $table->unsignedInteger('order_status_id');
            $table->timestamps();

            $table->unique(['opencart_setting_id', 'oc_status_id'], 'oc_status_map_store_status_unique');
            $table->foreign('opencart_setting_id')
                  ->references('id')
                  ->on('opencart_settings')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opencart_order_status_map');
    }
};
