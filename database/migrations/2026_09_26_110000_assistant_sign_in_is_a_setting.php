<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'mcp_sign_in')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('mcp_sign_in')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'mcp_sign_in')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('mcp_sign_in');
            });
        }
    }
};
