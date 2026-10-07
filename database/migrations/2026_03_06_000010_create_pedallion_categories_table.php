<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedallion_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pedallion_category_id')->unique();
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('name', 255);
            $table->unsignedInteger('level')->default(0);
            $table->boolean('leaf')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedallion_categories');
    }
};
