<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API Applications — 3rd-party clients granted scoped token access to the
 * ERP. Each client owns one or more Sanctum tokens (personal_access_tokens,
 * tokenable_type = App\Models\ApiClient) whose abilities encode the granted
 * resource:action scopes. Kept separate from human/mobile user tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable(); // users.id of the admin who created it
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
