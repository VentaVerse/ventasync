<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per activated product (core or module). Activation creates the row;
     * re-validation updates expiry and timestamps in place. is_revoked flips only
     * on an explicit 403 from the license server with reason revoked/expired.
     */
    public function up(): void
    {
        Schema::create('module_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('module_slug', 64)->unique();
            $table->string('license_key', 64);
            $table->string('domain', 190);
            $table->enum('license_type', ['trial', 'full'])->default('full');
            $table->dateTime('expires_at');
            $table->dateTime('maintenance_expires_at')->nullable();
            $table->dateTime('activated_at');
            $table->dateTime('last_validated_at');
            $table->boolean('is_revoked')->default(false);
            $table->string('revoked_reason', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_licenses');
    }
};
