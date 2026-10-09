<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

final class SchemaSnapshot
{
    private const INT_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    public static function read(): array
    {
        $db = DB::getDatabaseName();
        $mariadb = str_contains(strtolower((string) DB::selectOne('select version() as v')->v), 'mariadb');
        $json = $mariadb ? self::mariadbJsonColumns($db) : [];

        $tables = [];
        foreach (DB::select('select table_name as t, engine as e, table_collation as c from information_schema.tables where table_schema = ? and table_type = ?', [$db, 'BASE TABLE']) as $row) {
            if ($row->t === 'migrations') {
                continue;
            }
            $tables[$row->t] = ['engine' => $row->e, 'collation' => $row->c, 'columns' => [], 'indexes' => [], 'foreign_keys' => []];
        }

        $columns = DB::select('select table_name as t, column_name as n, column_default as d, is_nullable as nl, data_type as dt, column_type as ct,
                collation_name as co, extra as x, column_comment as cm
            from information_schema.columns where table_schema = ? order by table_name, ordinal_position', [$db]);
        foreach ($columns as $c) {
            if (! isset($tables[$c->t])) {
                continue;
            }
            $tables[$c->t]['columns'][] = self::column($c, $tables[$c->t]['collation'], isset($json[$c->t][$c->n]));
        }

        foreach (DB::select('select table_name as t, index_name as n, non_unique as nu, column_name as c, index_type as it
            from information_schema.statistics where table_schema = ? order by table_name, index_name, seq_in_index', [$db]) as $i) {
            if (! isset($tables[$i->t])) {
                continue;
            }
            $tables[$i->t]['indexes'][$i->n] ??= ['unique' => (int) $i->nu === 0, 'type' => strtolower($i->it), 'columns' => []];
            $tables[$i->t]['indexes'][$i->n]['columns'][] = $i->c;
        }

        foreach (DB::select('select k.table_name as t, k.constraint_name as n, k.column_name as c, k.referenced_table_name as rt,
                k.referenced_column_name as rc, r.update_rule as ur, r.delete_rule as dr
            from information_schema.key_column_usage k
            join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema
                and r.constraint_name = k.constraint_name and r.table_name = k.table_name
            where k.table_schema = ? and k.referenced_table_name is not null
            order by k.table_name, k.constraint_name, k.ordinal_position', [$db]) as $f) {
            if (! isset($tables[$f->t])) {
                continue;
            }
            $tables[$f->t]['foreign_keys'][$f->n] ??= ['columns' => [], 'references' => $f->rt, 'on' => [], 'on_update' => self::rule($f->ur), 'on_delete' => self::rule($f->dr)];
            $tables[$f->t]['foreign_keys'][$f->n]['columns'][] = $f->c;
            $tables[$f->t]['foreign_keys'][$f->n]['on'][] = $f->rc;
        }

        ksort($tables);
        foreach ($tables as &$table) {
            ksort($table['indexes']);
            ksort($table['foreign_keys']);
        }

        return $tables;
    }

    public static function differences(array $actual, array $target): array
    {
        $out = [];
        foreach (array_diff(array_keys($target), array_keys($actual)) as $t) {
            $out[] = "table {$t} is missing";
        }
        foreach (array_diff(array_keys($actual), array_keys($target)) as $t) {
            $out[] = "table {$t} is not in the target";
        }
        foreach (array_intersect(array_keys($actual), array_keys($target)) as $t) {
            $a = $actual[$t];
            $b = $target[$t];
            foreach (['engine', 'collation'] as $k) {
                if ($a[$k] !== $b[$k]) {
                    $out[] = "{$t}: {$k} is {$a[$k]}, target {$b[$k]}";
                }
            }
            if ($a['columns'] !== $b['columns']) {
                $ac = array_column($a['columns'], null, 'name');
                $bc = array_column($b['columns'], null, 'name');
                foreach (array_unique(array_merge(array_keys($ac), array_keys($bc))) as $c) {
                    if (($ac[$c] ?? null) !== ($bc[$c] ?? null)) {
                        $out[] = "{$t}.{$c}: " . json_encode($ac[$c] ?? null) . ' target ' . json_encode($bc[$c] ?? null);
                    }
                }
                if (array_keys($ac) !== array_keys($bc) && array_diff(array_keys($ac), array_keys($bc)) === [] && array_diff(array_keys($bc), array_keys($ac)) === []) {
                    $out[] = "{$t}: column order differs";
                }
            }
            foreach (['indexes', 'foreign_keys'] as $k) {
                foreach (array_unique(array_merge(array_keys($a[$k]), array_keys($b[$k]))) as $n) {
                    if (($a[$k][$n] ?? null) !== ($b[$k][$n] ?? null)) {
                        $out[] = "{$t} {$k} {$n}: " . json_encode($a[$k][$n] ?? null) . ' target ' . json_encode($b[$k][$n] ?? null);
                    }
                }
            }
        }

        return $out;
    }

    private static function rule(string $rule): string
    {
        return $rule === 'NO ACTION' ? 'RESTRICT' : $rule;
    }

    private static function mariadbJsonColumns(string $db): array
    {
        $out = [];
        foreach (DB::select('select table_name as t, constraint_name as n, check_clause as c from information_schema.check_constraints where constraint_schema = ?', [$db]) as $row) {
            if (preg_match('/^json_valid\(`([^`]+)`\)$/i', trim((string) $row->c), $m)) {
                $out[$row->t][$m[1]] = true;
            }
        }

        return $out;
    }

    private static function column(object $c, string $tableCollation, bool $mariadbJson): array
    {
        $type = strtolower((string) $c->ct);
        $dataType = strtolower((string) $c->dt);
        $extra = strtolower((string) $c->x);
        if ($mariadbJson && $dataType === 'longtext') {
            $type = 'json';
            $dataType = 'json';
        }
        if (in_array($dataType, self::INT_TYPES, true) && $type !== 'tinyint(1)' && ! str_starts_with($type, 'tinyint(1) ')) {
            $type = preg_replace('/^(' . implode('|', self::INT_TYPES) . ')\(\d+\)/', '$1', $type);
        }

        $default = $c->d;
        $current = false;
        if ($default !== null) {
            if (strtoupper($default) === 'NULL') {
                $default = null;
            } elseif (preg_match('/^current_timestamp(\(\))?$/i', $default)) {
                $default = null;
                $current = true;
            } elseif (preg_match("/^'(.*)'$/s", $default, $m)) {
                $default = str_replace("''", "'", $m[1]);
            }
        }

        $collation = $c->co;
        if ($dataType === 'json' || $collation === $tableCollation) {
            $collation = null;
        }

        return [
            'name' => $c->n,
            'type' => $type,
            'nullable' => $c->nl === 'YES',
            'default' => $default,
            'default_current' => $current,
            'on_update_current' => str_contains($extra, 'on update current_timestamp'),
            'auto_increment' => str_contains($extra, 'auto_increment'),
            'collation' => $collation,
            'comment' => (string) $c->cm,
        ];
    }
}
