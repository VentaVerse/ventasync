<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait SchemaRenames
{
    protected function renameTable(string $from, string $to): void
    {
        if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }

    protected function renameColumn(string $table, string $from, string $to): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            ColumnRename::run($table, $from, $to);
        }
    }

    protected function renameIndex(string $table, string $from, string $to): void
    {
        $names = Schema::hasTable($table) ? array_column(Schema::getIndexes($table), 'name') : [];
        if (in_array($from, $names, true) && ! in_array($to, $names, true)) {
            Schema::table($table, fn (Blueprint $t) => $t->renameIndex($from, $to));
        }
    }

    protected function movePermission(string $from, string $to): void
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

    protected function renameColumns(array $columns): void
    {
        foreach ($columns as [$table, $from, $to]) {
            $this->renameColumn($table, $from, $to);
        }
    }

    protected function restoreColumns(array $columns): void
    {
        foreach (array_reverse($columns) as [$table, $from, $to]) {
            $this->renameColumn($table, $to, $from);
        }
    }

    protected function renameIndexes(array $indexes): void
    {
        foreach ($indexes as [$table, $from, $to]) {
            $this->renameIndex($table, $from, $to);
        }
    }

    protected function restoreIndexes(array $indexes): void
    {
        foreach ($indexes as [$table, $from, $to]) {
            $this->renameIndex($table, $to, $from);
        }
    }
}
