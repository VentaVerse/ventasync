<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ColumnRename
{
    private const MYSQL_REFUSES_RENAME = [1845, 1846, 3959];

    public static function run(string $table, string $from, string $to): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn($from, $to));
        } catch (QueryException $e) {
            if (! in_array((int) ($e->errorInfo[1] ?? 0), self::MYSQL_REFUSES_RENAME, true)) {
                throw $e;
            }
            self::aroundConstraints($table, $from, $to);
        }
    }

    // MySQL refuses these renames on tables copied from MariaDB, whose json columns carry CHECK constraints: the keys and checks come off and go back on.
    private static function aroundConstraints(string $table, string $from, string $to): void
    {
        $keys = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($j) {
                $j->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
            })
            ->where('k.TABLE_SCHEMA', DB::getDatabaseName())
            ->where('k.TABLE_NAME', $table)
            ->where('k.COLUMN_NAME', $from)
            ->whereNotNull('k.REFERENCED_TABLE_NAME')
            ->get(['k.CONSTRAINT_NAME as name', 'k.REFERENCED_TABLE_NAME as parent', 'k.REFERENCED_COLUMN_NAME as parent_column', 'r.DELETE_RULE as on_delete', 'r.UPDATE_RULE as on_update']);

        $checks = DB::table('information_schema.TABLE_CONSTRAINTS as t')
            ->join('information_schema.CHECK_CONSTRAINTS as c', function ($j) {
                $j->on('c.CONSTRAINT_SCHEMA', '=', 't.CONSTRAINT_SCHEMA')->on('c.CONSTRAINT_NAME', '=', 't.CONSTRAINT_NAME');
            })
            ->where('t.TABLE_SCHEMA', DB::getDatabaseName())
            ->where('t.TABLE_NAME', $table)
            ->where('t.CONSTRAINT_TYPE', 'CHECK')
            ->where('c.CHECK_CLAUSE', 'like', '%`' . $from . '`%')
            ->get(['t.CONSTRAINT_NAME as name', 'c.CHECK_CLAUSE as clause']);

        foreach ($keys as $key) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $key->name));
        }
        foreach ($checks as $check) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP CHECK `%s`', $table, $check->name));
        }

        DB::statement(sprintf('ALTER TABLE `%s` RENAME COLUMN `%s` TO `%s`', $table, $from, $to));

        foreach ($checks as $check) {
            DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $check->name, str_replace('`' . $from . '`', '`' . $to . '`', $check->clause)));
        }
        foreach ($keys as $key) {
            $name = $key->name === $table . '_' . $from . '_foreign' ? $table . '_' . $to . '_foreign' : $key->name;
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s',
                $table, $name, $to, $key->parent, $key->parent_column, $key->on_delete, $key->on_update
            ));
        }
    }
}
