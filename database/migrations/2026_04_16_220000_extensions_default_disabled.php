<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Change the default for extensions.enabled from true to false.
     * Existing rows keep their current value — only future inserts default to disabled.
     * This makes fresh installs require an explicit enable action from the admin,
     * which is especially important for licensed extensions that would otherwise
     * clutter the sidebar before their license is activated.
     */
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->boolean('enabled')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->change();
        });
    }
};
