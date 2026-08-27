<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An API application can be opened to an AI assistant over MCP (#131).
 *
 * Off by default: an application built for the mobile app must not become
 * an AI tool surface by accident. Allowed IPs and the per-minute cap are
 * both optional and apply to the whole application, API and MCP alike.
 * Empty means anywhere / no cap, which keeps the 2026-07-22 decision that
 * the API itself is not rate limited.
 */
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
