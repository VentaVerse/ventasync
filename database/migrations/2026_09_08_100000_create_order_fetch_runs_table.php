<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->json('options')->nullable();
            $table->json('cursor')->nullable();
            $table->unsignedInteger('page')->default(0);
            $table->unsignedInteger('pages')->nullable();
            $table->unsignedInteger('total')->nullable();
            $table->unsignedInteger('read')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 16)->default('running');
            $table->text('last_error')->nullable();
            $table->json('ledger')->nullable();
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
