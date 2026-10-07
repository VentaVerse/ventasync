<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extensions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name', 128);
            $table->string('version', 32)->default('1.0.0');
            $table->text('description')->nullable();
            $table->string('author', 128)->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('license_key', 255)->nullable();
            $table->json('manifest')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extensions');
    }
};
