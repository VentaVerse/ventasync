<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $exists = DB::table('permissions')->where('key', 'manage_tiktok')->exists();
        if (!$exists) {
            DB::table('permissions')->insert([
                'key' => 'manage_tiktok',
                'label' => 'TikTok Shop',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'manage_tiktok')->delete();
    }
};
