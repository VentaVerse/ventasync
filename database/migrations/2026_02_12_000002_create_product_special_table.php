<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('catalog.prefix');

        if (Schema::hasTable($p . 'product_special')) {
            return;
        }

        Schema::create($p . 'product_special', function (Blueprint $table) {
            $table->integer('product_special_id')->autoIncrement();
            $table->integer('product_id');
            $table->integer('customer_group_id')->default(0);
            $table->integer('priority')->default(0);
            $table->decimal('price', 15, 4)->default(0);
            $table->date('date_start')->nullable();
            $table->date('date_end')->nullable();
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        $p = config('catalog.prefix');
        Schema::dropIfExists($p . 'product_special');
    }
};
