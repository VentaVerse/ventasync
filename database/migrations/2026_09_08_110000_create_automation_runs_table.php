<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automation_runs')) {
            return;
        }
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scheduled_job_id');
            $table->string('integration', 32);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('label', 32)->default('products');
            $table->json('units')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('ok')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 16)->default('running');
            $table->text('last_error')->nullable();
            $table->json('ledger')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->index(['scheduled_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
