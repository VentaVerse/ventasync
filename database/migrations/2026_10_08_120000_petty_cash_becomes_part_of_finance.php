<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        'pettycash/ledger' => 'finance/petty_cash_ledger',
        'pettycash/settings' => 'finance/petty_cash_settings',
    ];

    public function up(): void
    {
        $this->moveKeys(self::KEYS);
        $this->moveRow('pettycash', 'finance');
    }

    public function down(): void
    {
        $this->moveKeys(array_flip(self::KEYS));
        $this->moveRow('finance', 'pettycash');
    }

    private function moveKeys(array $map): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($map as $from => $to) {
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
    }

    private function moveRow(string $from, string $to): void
    {
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
            'name' => $to === 'finance' ? ($manifest['name'] ?? 'Finance') : 'Petty Cash',
            'description' => $manifest['description'] ?? $row->description,
            'manifest' => json_encode($manifest),
        ]);
    }
};
