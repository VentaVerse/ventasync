<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    }
};
