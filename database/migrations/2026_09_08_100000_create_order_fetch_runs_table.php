<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fetch of orders is a run of one-page steps the page drives (the owner,
 * 2026-09-07, after a fourteen-day fetch on a sandbox store died at the
 * server's limit behind a spinner). Every page is saved when it arrives,
 * the run remembers its place per store, and a stopped run can continue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_fetch_runs')) {
            return;
        }
        Schema::create('order_fetch_runs', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 32);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->date('date_from');
            $table->date('date_to');
            $table->json('options')->nullable();      // no_stock, status: the form's own switches
            $table->json('cursor')->nullable();       // the channel's place: offset, page token, window, page
            $table->unsignedInteger('page')->default(0);
            $table->unsignedInteger('pages')->nullable();
            $table->unsignedInteger('total')->nullable();
            $table->unsignedInteger('read')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 16)->default('running'); // running, stopped, failed, done
            $table->text('last_error')->nullable();
            $table->json('ledger')->nullable();       // the last twenty page sentences
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->index(['integration', 'store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fetch_runs');
    }
};
