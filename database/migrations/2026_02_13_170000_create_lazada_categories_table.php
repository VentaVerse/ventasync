<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lazada_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->index();
            $table->string('name', 255)->index();
            $table->boolean('leaf')->default(false)->index();
            $table->boolean('var')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedInteger('level')->default(0)->index();
            $table->timestamps();

            $table->unique('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_categories');
    }
};
