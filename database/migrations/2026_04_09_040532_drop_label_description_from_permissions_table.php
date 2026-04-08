<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Labels and descriptions are now resolved from lang files
     * (resources/lang/en/permissions.php) via model accessors.
     * These columns are no longer needed.
     */
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['label', 'description']);
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('label')->default('')->after('key');
            $table->string('description')->nullable()->after('label');
        });
    }
};
