<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phone app sign-ins are named after the product. Tokens issued under the
 * earlier name are renamed, so signing in again replaces them instead of
 * leaving them behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('name', 'agila-mobile')->update(['name' => 'ventasync-mobile']);
        }
    }

    public function down(): void
    {
        // The earlier name is retired; nothing to restore.
    }
};
