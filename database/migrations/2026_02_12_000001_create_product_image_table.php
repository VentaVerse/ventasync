<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('catalog.prefix');

        if (Schema::hasTable($p . 'product_image')) {
            return;
        }

        Schema::create($p . 'product_image', function (Blueprint $table) {
            $table->integer('product_image_id')->autoIncrement();
            $table->integer('product_id');
            $table->string('image', 255)->default('');
            $table->integer('sort_order')->default(0);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        $p = config('catalog.prefix');
        Schema::dropIfExists($p . 'product_image');
    }
};
