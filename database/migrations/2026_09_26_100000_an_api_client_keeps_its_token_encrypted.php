<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An API application keeps its current token encrypted with the app key, so
 * an operator with manage access can view it again. Sanctum's own column
 * stays a hash; this is a second, readable copy. NULL for a token issued
 * before the column existed, which cannot be recovered and has to be
 * rotated to become viewable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->text('token_secret')->nullable()->after('calls_per_minute');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropColumn('token_secret');
        });
    }
};
