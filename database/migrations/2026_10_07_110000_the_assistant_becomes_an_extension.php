<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->movePermissions('ai_tools/assistant', 'assistant/overview');

        $file = base_path('extensions/assistant/extension.json');
        if (! Schema::hasTable('extensions') || ! File::exists($file) || DB::table('extensions')->where('id', 'assistant')->exists()) {
            return;
        }
        if (! Schema::hasTable('users') || ! DB::table('users')->exists()) {
            return;
        }

        $manifest = json_decode(File::get($file), true) ?: [];
        DB::table('extensions')->insert([
            'id' => 'assistant',
            'name' => $manifest['name'] ?? 'AI Assistant',
            'version' => $manifest['version'] ?? '1.0.0',
            'description' => $manifest['description'] ?? null,
            'author' => $manifest['author'] ?? null,
            'enabled' => true,
            'manifest' => json_encode($manifest),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $this->movePermissions('assistant/overview', 'ai_tools/assistant');

        if (Schema::hasTable('extensions')) {
            DB::table('extensions')->where('id', 'assistant')->delete();
        }
    }

    private function movePermissions(string $from, string $to): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (['view', 'manage'] as $tier) {
            $old = DB::table('permissions')->where('key', $tier . '_' . $from)->value('id');
            if ($old === null) {
                continue;
            }

            $existing = DB::table('permissions')->where('key', $tier . '_' . $to)->value('id');
            if ($existing === null) {
                DB::table('permissions')->where('id', $old)->update(['key' => $tier . '_' . $to]);
                continue;
            }

            if (Schema::hasTable('user_group_permissions')) {
                foreach (DB::table('user_group_permissions')->where('permission_id', $old)->pluck('user_group_id') as $groupId) {
                    DB::table('user_group_permissions')->insertOrIgnore(['user_group_id' => $groupId, 'permission_id' => $existing]);
                }
                DB::table('user_group_permissions')->where('permission_id', $old)->delete();
            }
            DB::table('permissions')->where('id', $old)->delete();
        }
    }
};
