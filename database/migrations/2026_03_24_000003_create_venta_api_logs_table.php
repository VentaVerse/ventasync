<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_setting_id')->nullable();
            $table->string('method', 10);
            $table->string('endpoint', 500);
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->unsignedInteger('response_time_ms')->default(0);
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->boolean('ok')->default(false);
            $table->timestamps();

            $table->index('venta_setting_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_api_logs');
    }
};
