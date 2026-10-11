<?php

namespace App\Support\Database;

use RuntimeException;

final class SchemaAligner
{
    private const NUMERIC = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'];

    public static function statements(array $actual, array $target): array
    {
        self::assertSameShape($actual, $target);

        $modify = [];
        $dropIndexes = [];
        $renameIndexes = [];
        $createIndexes = [];
        $dropForeign = [];
        $addForeign = [];
        $touchedColumns = [];

        foreach ($target as $table => $want) {
            $have = $actual[$table];

            if (($have['indexes']['PRIMARY'] ?? null) !== ($want['indexes']['PRIMARY'] ?? null)) {
                throw new RuntimeException("{$table}: the primary key differs from the target; this upgrade does not change primary keys.");
            }

            $haveColumns = array_column($have['columns'], null, 'name');
            $orderDiffers = array_column($have['columns'], 'name') !== array_column($want['columns'], 'name');
            $previous = null;
            foreach ($want['columns'] as $column) {
                if ($orderDiffers || $haveColumns[$column['name']] !== $column) {
                    $modify[] = "ALTER TABLE `{$table}` MODIFY `{$column['name']}` " . self::definition($column)
                        . ($orderDiffers ? ($previous === null ? ' FIRST' : " AFTER `{$previous}`") : '');
                    if ($haveColumns[$column['name']] !== $column) {
                        $touchedColumns[$table][$column['name']] = true;
                    }
                }
                $previous = $column['name'];
            }

            $haveIndexes = array_diff_key($have['indexes'], ['PRIMARY' => true]);
            $wantIndexes = array_diff_key($want['indexes'], ['PRIMARY' => true]);
            $wantBySignature = [];
            foreach ($wantIndexes as $name => $index) {
                $wantBySignature[self::signature($index)] = $name;
            }
            $kept = [];
            foreach ($haveIndexes as $name => $index) {
                $signature = self::signature($index);
                $wanted = $wantBySignature[$signature] ?? null;
                if ($wanted === null || isset($kept[$wanted])) {
                    $dropIndexes[] = [$table, $name];
                    continue;
                }
                $kept[$wanted] = true;
                if ($wanted !== $name) {
                    $renameIndexes[] = [$table, $name, $wanted];
                }
            }
            foreach ($wantIndexes as $name => $index) {
                if (! isset($kept[$name])) {
                    $createIndexes[] = [$table, $name, $index];
                }
            }

            $tableLosesIndexes = array_filter($dropIndexes, fn ($d) => $d[0] === $table) !== [];
            foreach ($have['foreign_keys'] as $name => $fk) {
                if (($want['foreign_keys'][$name] ?? null) !== $fk || $tableLosesIndexes) {
                    $dropForeign[$table . '.' . $name] = [$table, $name];
                }
            }
            foreach ($want['foreign_keys'] as $name => $fk) {
                if (($have['foreign_keys'][$name] ?? null) !== $fk || $tableLosesIndexes) {
                    $addForeign[$table . '.' . $name] = [$table, $name, $fk];
                }
            }
        }

        foreach ($target as $table => $want) {
            foreach ($want['foreign_keys'] as $name => $fk) {
                $touched = array_filter($fk['columns'], fn ($c) => isset($touchedColumns[$table][$c]))
                    || array_filter($fk['on'], fn ($c) => isset($touchedColumns[$fk['references']][$c]));
                if ($touched) {
                    if (isset($actual[$table]['foreign_keys'][$name])) {
                        $dropForeign[$table . '.' . $name] = [$table, $name];
                    }
                    $addForeign[$table . '.' . $name] = [$table, $name, $fk];
                }
            }
        }

        $sql = ['SET FOREIGN_KEY_CHECKS=0'];
        foreach ($dropForeign as [$table, $name]) {
            $sql[] = "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`";
        }
        array_push($sql, ...$modify);
        foreach ($dropIndexes as [$table, $name]) {
            $sql[] = "ALTER TABLE `{$table}` DROP INDEX `{$name}`";
        }
        foreach ($renameIndexes as $i => [$table, $from, $to]) {
            $sql[] = "ALTER TABLE `{$table}` RENAME INDEX `{$from}` TO `vs_rename_{$i}`";
        }
        foreach ($renameIndexes as $i => [$table, $from, $to]) {
            $sql[] = "ALTER TABLE `{$table}` RENAME INDEX `vs_rename_{$i}` TO `{$to}`";
        }
        foreach ($createIndexes as [$table, $name, $index]) {
            $kind = $index['type'] === 'fulltext' ? 'FULLTEXT INDEX' : ($index['unique'] ? 'UNIQUE INDEX' : 'INDEX');
            $sql[] = "ALTER TABLE `{$table}` ADD {$kind} `{$name}` (" . self::columnList($index['columns']) . ')';
        }
        foreach ($addForeign as [$table, $name, $fk]) {
            $sql[] = "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (" . self::columnList($fk['columns']) . ")"
                . " REFERENCES `{$fk['references']}` (" . self::columnList($fk['on']) . ")"
                . " ON DELETE {$fk['on_delete']} ON UPDATE {$fk['on_update']}";
        }
        $sql[] = 'SET FOREIGN_KEY_CHECKS=1';

        return count($sql) === 2 ? [] : $sql;
    }

    public static function createMissing(array $actual, array $target, array $allowed): array
    {
        $sql = [];
        foreach (array_intersect(array_diff(array_keys($target), array_keys($actual)), $allowed) as $table) {
            $want = $target[$table];
            $parts = array_map(fn ($c) => "`{$c['name']}` " . self::definition($c), $want['columns']);
            if (isset($want['indexes']['PRIMARY'])) {
                $parts[] = 'PRIMARY KEY (' . self::columnList($want['indexes']['PRIMARY']['columns']) . ')';
            }
            foreach (array_diff_key($want['indexes'], ['PRIMARY' => true]) as $name => $index) {
                $kind = $index['type'] === 'fulltext' ? 'FULLTEXT KEY' : ($index['unique'] ? 'UNIQUE KEY' : 'KEY');
                $parts[] = "{$kind} `{$name}` (" . self::columnList($index['columns']) . ')';
            }
            $charset = explode('_', $want['collation'])[0];
            $sql[] = "CREATE TABLE `{$table}` (\n  " . implode(",\n  ", $parts) . "\n) ENGINE={$want['engine']} DEFAULT CHARSET={$charset} COLLATE={$want['collation']}";
        }

        return $sql;
    }

    public static function definition(array $column): string
    {
        $sql = $column['type'];
        if ($column['collation'] !== null) {
            $sql .= ' COLLATE ' . $column['collation'];
        }
        $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';
        if ($column['default_current']) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($column['default'] !== null) {
            $base = strtolower(strtok($column['type'], '( '));
            $sql .= ' DEFAULT ' . (in_array($base, self::NUMERIC, true) && is_numeric($column['default'])
                ? $column['default']
                : "'" . str_replace(["\\", "'"], ["\\\\", "''"], $column['default']) . "'");
        } elseif ($column['nullable'] && ! $column['auto_increment']) {
            $sql .= ' DEFAULT NULL';
        }
        if ($column['on_update_current']) {
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
        }
        if ($column['auto_increment']) {
            $sql .= ' AUTO_INCREMENT';
        }
        if ($column['comment'] !== '') {
            $sql .= " COMMENT '" . str_replace("'", "''", $column['comment']) . "'";
        }

        return $sql;
    }

    private static function assertSameShape(array $actual, array $target): void
    {
        $problems = [];
        foreach (array_diff(array_keys($target), array_keys($actual)) as $t) {
            $problems[] = "table {$t} is missing";
        }
        foreach (array_diff(array_keys($actual), array_keys($target)) as $t) {
            $problems[] = "table {$t} is not expected";
        }
        foreach (array_intersect(array_keys($actual), array_keys($target)) as $t) {
            $have = array_column($actual[$t]['columns'], 'name');
            $want = array_column($target[$t]['columns'], 'name');
            foreach (array_diff($want, $have) as $c) {
                $problems[] = "{$t}.{$c} is missing";
            }
            foreach (array_diff($have, $want) as $c) {
                $problems[] = "{$t}.{$c} is not expected";
            }
            if ($actual[$t]['engine'] !== $target[$t]['engine'] || $actual[$t]['collation'] !== $target[$t]['collation']) {
                $problems[] = "{$t}: engine or collation differs";
            }
        }
        if ($problems !== []) {
            throw new RuntimeException("The database does not have the expected tables and columns, so nothing was changed:\n" . implode("\n", $problems));
        }
    }

    private static function signature(array $index): string
    {
        return ($index['unique'] ? 'u' : 'i') . ':' . $index['type'] . ':' . implode(',', $index['columns']);
    }

    private static function columnList(array $columns): string
    {
        return implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
    }
}
