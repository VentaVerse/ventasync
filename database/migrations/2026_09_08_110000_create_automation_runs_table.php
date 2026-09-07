<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run now on the Automations tab as a run of short steps the page drives
 * (the owner, 2026-09-07: "Running Push Stock. It takes as long as the job
 * takes. Please keep this tab open." needs a bar like "Product 10/200").
 * A push walks its products ten per request; the run keeps the count,
 * the place and the verdict, and the job row is stamped when it ends.
 */
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
            $table->string('label', 32)->default('products'); // the unit's word: products, orders
            $table->json('units')->nullable();                 // every unit, in order
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);       // units finished, ok or not
            $table->unsignedInteger('ok')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 16)->default('running');  // running, stopped, failed, done
            $table->text('last_error')->nullable();
            $table->json('ledger')->nullable();                // the last twenty step sentences
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
