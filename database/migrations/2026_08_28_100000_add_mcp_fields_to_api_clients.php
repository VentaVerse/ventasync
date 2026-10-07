<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->boolean('mcp_enabled')->default(false)->after('active');
            $table->json('allowed_ips')->nullable()->after('mcp_enabled');
            $table->unsignedSmallInteger('calls_per_minute')->nullable()->after('allowed_ips');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropColumn(['mcp_enabled', 'allowed_ips', 'calls_per_minute']);
        });
    }
};
