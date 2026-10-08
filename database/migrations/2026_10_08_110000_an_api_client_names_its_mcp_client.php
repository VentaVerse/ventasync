<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('api_clients', 'assistant') && ! Schema::hasColumn('api_clients', 'mcp_client')) {
            Schema::table('api_clients', fn (Blueprint $table) => $table->renameColumn('assistant', 'mcp_client'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('api_clients', 'mcp_client') && ! Schema::hasColumn('api_clients', 'assistant')) {
            Schema::table('api_clients', fn (Blueprint $table) => $table->renameColumn('mcp_client', 'assistant'));
        }
    }
};
