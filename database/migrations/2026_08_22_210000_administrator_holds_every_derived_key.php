<?php

use App\Services\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('user_groups')) {
            return;
        }

        $catalog = app(PermissionCatalogue::class);
        $ids = DB::table('permissions')->pluck('id', 'key')->all();

        foreach ($catalog->keys() as $key) {
            if (! isset($ids[$key])) {
                $ids[$key] = DB::table('permissions')->insertGetId(['key' => $key]);
            }
        }

        $adminId = DB::table('user_groups')
            ->whereRaw('LOWER(name) = ?', ['administrator'])
            ->value('id');

        if (! $adminId) {
            return;
        }

        $held = DB::table('user_group_permissions')
            ->where('user_group_id', $adminId)
            ->pluck('permission_id')
            ->all();

        $rows = [];

        foreach ($catalog->keys() as $key) {
            if (isset($ids[$key]) && ! in_array($ids[$key], $held, true)) {
                $rows[] = ['user_group_id' => $adminId, 'permission_id' => $ids[$key]];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('user_group_permissions')->insert($chunk);
        }
    }

    public function down(): void
    {
    }
};
