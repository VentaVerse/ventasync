<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->move('assistant', 'mcp');
    }

    public function down(): void
    {
        $this->move('mcp', 'assistant');
    }

    private function move(string $from, string $to): void
    {
        if (Schema::hasTable('permissions')) {
            foreach (['view', 'manage'] as $tier) {
                $old = DB::table('permissions')->where('key', $tier . '_' . $from . '/overview')->value('id');
                if ($old === null) {
                    continue;
                }

                $existing = DB::table('permissions')->where('key', $tier . '_' . $to . '/overview')->value('id');
                if ($existing === null) {
                    DB::table('permissions')->where('id', $old)->update(['key' => $tier . '_' . $to . '/overview']);
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

        if (! Schema::hasTable('extensions')) {
            return;
        }

        $row = DB::table('extensions')->where('id', $from)->first();
        if ($row === null || DB::table('extensions')->where('id', $to)->exists()) {
            return;
        }

        $manifest = json_decode((string) $row->manifest, true) ?: [];
        $file = base_path('extensions/' . $to . '/extension.json');
        if (File::exists($file)) {
            $manifest = json_decode(File::get($file), true) ?: $manifest;
        }
        $manifest['id'] = $to;

        DB::table('extensions')->where('id', $from)->update([
            'id' => $to,
            'name' => $to === 'mcp' ? ($manifest['name'] ?? 'MCP Server') : 'AI Assistant',
            'description' => $manifest['description'] ?? $row->description,
            'manifest' => json_encode($manifest),
        ]);
    }
};
