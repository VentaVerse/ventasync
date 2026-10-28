<?php

namespace App\Extensions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ExtensionData
{
    public static function path(string $id): string
    {
        return base_path("extensions/{$id}/migrations");
    }

    public static function migrate(string $id): void
    {
        $path = self::path($id);
        if (! is_dir($path)) {
            return;
        }
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }
        $migrator->run([$path]);
    }

    public static function delete(string $id): void
    {
        $path = self::path($id);
        if (is_dir($path)) {
            app('migrator')->reset([$path]);
        }
    }

    public static function tables(string $id): array
    {
        $tables = [];
        foreach (glob(self::path($id) . '/*.php') ?: [] as $file) {
            preg_match_all("/Schema::create\(\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $found);
            array_push($tables, ...$found[1]);
        }

        return array_values(array_filter(array_unique($tables), fn ($table) => Schema::hasTable($table)));
    }

    public static function counts(string $id): array
    {
        $counts = [];
        foreach (self::tables($id) as $table) {
            $rows = DB::table($table)->count();
            if ($rows > 0) {
                $counts[self::label($id, $table)] = ($counts[self::label($id, $table)] ?? 0) + $rows;
            }
        }

        return $counts;
    }

    public static function rows(string $id): int
    {
        return array_sum(self::counts($id));
    }

    private static function label(string $id, string $table): string
    {
        $words = Str::of($table)->replaceStart("ch_{$id}_", '')->replace('_', ' ')->ucfirst()->toString();

        return preg_replace_callback('/\b(api|sku|pdf)\b/i', fn ($m) => strtoupper($m[1]), $words);
    }
}
