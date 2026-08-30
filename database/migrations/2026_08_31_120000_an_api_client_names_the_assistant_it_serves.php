<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Tools (2026-08-31): an application connected from the AI Tools page
 * remembers which assistant it was made for (claude_code, claude_desktop,
 * cursor, other), so the page can show the right paste-ready snippet and
 * the right name. NULL for applications made under Settings, API, which
 * are developer integrations and stay on that page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->string('assistant', 32)->nullable()->after('mcp_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropColumn('assistant');
        });
    }
};
