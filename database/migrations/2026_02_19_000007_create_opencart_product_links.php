<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opencart_product_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('opencart_setting_id');
            $table->unsignedInteger('oc_product_id');
            $table->unsignedInteger('product_id');
            $table->string('sku', 64)->default('');

            $table->unique(['opencart_setting_id', 'oc_product_id']);
            $table->index('product_id');
            $table->index('sku');

            $table->foreign('opencart_setting_id')
                ->references('id')
                ->on('opencart_settings')
                ->onDelete('cascade');

            $table->timestamps();
        });

        Schema::table('opencart_sync_log', function (Blueprint $table) {
            $table->unsignedBigInteger('opencart_setting_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opencart_product_links');

        Schema::table('opencart_sync_log', function (Blueprint $table) {
            $table->dropColumn('opencart_setting_id');
        });
    }
};
