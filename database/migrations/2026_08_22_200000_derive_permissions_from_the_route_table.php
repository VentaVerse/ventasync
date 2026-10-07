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

        $adminId = DB::table('user_groups')->whereRaw('LOWER(name) = ?', ['administrator'])->value('id');

        if ($adminId) {
            $held = DB::table('user_group_permissions')->where('user_group_id', $adminId)
                ->pluck('permission_id')->all();

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

        $map = $this->derivedMap($catalog);

        foreach (DB::table('user_groups')->pluck('id') as $groupId) {
            $held = DB::table('user_group_permissions as ugp')
                ->join('permissions as p', 'p.id', '=', 'ugp.permission_id')
                ->where('ugp.user_group_id', $groupId)
                ->pluck('p.key')
                ->all();

            $wanted = [];

            foreach ($held as $old) {
                foreach ($map[$old] ?? [] as $new) {
                    if (isset($ids[$new])) {
                        $wanted[$ids[$new]] = true;
                    }
                }
            }

            if ($wanted === []) {
                continue;
            }

            $already = DB::table('user_group_permissions')
                ->where('user_group_id', $groupId)
                ->pluck('permission_id')
                ->all();

            $rows = [];

            foreach (array_diff(array_keys($wanted), $already) as $permissionId) {
                $rows[] = ['user_group_id' => $groupId, 'permission_id' => $permissionId];
            }

            if ($rows !== []) {
                DB::table('user_group_permissions')->insert($rows);
            }
        }
    }

    private function derivedMap(PermissionCatalogue $catalog): array
    {
        $map = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class] = explode('@', $action);
            $new = $catalog->keyForController($class);

            if (! $new) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'perm:')) {
                    continue;
                }

                foreach (preg_split('/[,|]/', substr($middleware, 5)) as $old) {
                    $old = trim($old);

                    if ($old !== '') {
                        $tier = str_starts_with($old, 'manage_') ? 'manage' : 'view';
                        $map[$old][$tier . '_' . $new] = true;
                    }
                }
            }
        }

        return array_map(fn ($set) => array_keys($set), $map);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $keys = app(PermissionCatalogue::class)->keys();
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id')->all();

        if ($ids !== []) {
            DB::table('user_group_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
