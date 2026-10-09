<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['view_catalog/manufacturer', 'view_catalog/brand'],
        ['manage_catalog/manufacturer', 'manage_catalog/brand'],
    ];

    private function tables(): array
    {
        $pfx = (string) config('catalog.prefix');

        return [[$pfx . 'manufacturer', $pfx . 'brand']];
    }

    private function columns(): array
    {
        $pfx = (string) config('catalog.prefix');

        return [
            [$pfx . 'brand', 'manufacturer_id', 'brand_id'],
            [$pfx . 'product', 'manufacturer_id', 'brand_id'],
            ['opencart_settings', 'last_manufacturer_sync_at', 'last_brand_sync_at'],
        ];
    }

    public function up(): void
    {
        foreach ($this->tables() as [$from, $to]) {
            $this->renameTable($from, $to);
        }
        foreach ($this->columns() as [$table, $from, $to]) {
            $this->renameColumn($table, $from, $to);
        }
        foreach (self::PERMISSIONS as [$from, $to]) {
            $this->movePermission($from, $to);
        }
        DB::table('activity_logs')->where('subject_type', 'Manufacturer')->update(['subject_type' => 'Brand']);
        if (Schema::hasTable('opencart_sync_log')) {
            DB::table('opencart_sync_log')->where('entity_type', 'manufacturer')->update(['entity_type' => 'brand']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('opencart_sync_log')) {
            DB::table('opencart_sync_log')->where('entity_type', 'brand')->update(['entity_type' => 'manufacturer']);
        }
        DB::table('activity_logs')->where('subject_type', 'Brand')->update(['subject_type' => 'Manufacturer']);
        foreach (array_reverse(self::PERMISSIONS) as [$from, $to]) {
            $this->movePermission($to, $from);
        }
        foreach (array_reverse($this->columns()) as [$table, $from, $to]) {
            $this->renameColumn($table, $to, $from);
        }
        foreach (array_reverse($this->tables()) as [$from, $to]) {
            $this->renameTable($to, $from);
        }
    }

    private function renameTable(string $from, string $to): void
    {
        if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }

    private function renameColumn(string $table, string $from, string $to): void
    {
        foreach ($this->tables() as [$old, $new]) {
            if ($table === $new && ! Schema::hasTable($new)) {
                $table = $old;
            }
        }
        if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn($from, $to));
        }
    }

    private function movePermission(string $from, string $to): void
    {
        $old = DB::table('permissions')->where('key', $from)->value('id');
        if ($old === null) {
            return;
        }
        $new = DB::table('permissions')->where('key', $to)->value('id');
        if ($new === null) {
            DB::table('permissions')->where('id', $old)->update(['key' => $to]);

            return;
        }
        foreach (DB::table('user_group_permissions')->where('permission_id', $old)->pluck('user_group_id') as $group) {
            DB::table('user_group_permissions')->insertOrIgnore(['user_group_id' => $group, 'permission_id' => $new]);
        }
        DB::table('user_group_permissions')->where('permission_id', $old)->delete();
        DB::table('permissions')->where('id', $old)->delete();
    }
};
