<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopee_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('pack', 128)->nullable();
            $table->string('method', 8);
            $table->string('api_path', 255);
            $table->boolean('auth_required')->default(false);

            $table->json('request_params')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->boolean('ok')->default(false);
            $table->json('response_body')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamps();

            $table->index(['api_path', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_api_logs');
    }
};
