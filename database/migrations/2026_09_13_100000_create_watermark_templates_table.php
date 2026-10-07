<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watermark_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('image_path');
            $table->string('position', 20)->default('bottom-right');
            $table->decimal('size_percent', 5, 2)->default(18);
            $table->decimal('margin_percent', 5, 2)->default(3);
            $table->decimal('opacity', 4, 3)->default(1);
            $table->boolean('stamp_main')->default(false);
            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_templates');
    }
};
