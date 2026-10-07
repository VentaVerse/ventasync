<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedallion_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->string('endpoint', 255);
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['endpoint', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedallion_api_logs');
    }
};
