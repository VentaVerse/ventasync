<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $keys = ['view_options', 'manage_options'];

    public function up(): void
    {
        $ids = DB::table('permissions')->whereIn('key', $this->keys)->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('user_group_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        foreach ($this->keys as $key) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['key' => $key]);
        }
    }
};
