<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_idempotency_keys')) {
            return;
        }

        Schema::create('app_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('idempotency_key', 100);
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('response')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->unique(['user_id', 'idempotency_key'], 'app_idem_user_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_idempotency_keys');
    }
};
