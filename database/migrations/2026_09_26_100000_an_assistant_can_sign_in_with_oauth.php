<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An assistant that signs in through OAuth (Claude, ChatGPT) is tied to the
 * OAuth client that signed in, so its sign-in resolves to it and deleting
 * it ends the sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('api_clients', 'oauth_client_id')) {
            Schema::table('api_clients', function (Blueprint $table) {
                $table->uuid('oauth_client_id')->nullable()->unique()->after('assistant');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('api_clients', 'oauth_client_id')) {
            Schema::table('api_clients', function (Blueprint $table) {
                $table->dropUnique(['oauth_client_id']);
                $table->dropColumn('oauth_client_id');
            });
        }
    }
};
