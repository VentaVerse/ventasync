<?php

namespace App\Support;

final class AppVersion
{
    private static ?string $current = null;

    public static function current(): string
    {
        if (self::$current === null) {
            $file = base_path('VERSION');
            self::$current = is_file($file) ? trim((string) file_get_contents($file)) : '0.0.0';
        }

        return self::$current;
    }

    public static function changelog(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $releases = [];
        $current = null;
        foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
            if (preg_match('/^## (\d+\.\d+\.\d+)(?: \(([\d-]+)\))?\s*$/', $line, $m)) {
                $releases[] = ['version' => $m[1], 'date' => $m[2] ?? null, 'lines' => []];
                $current = array_key_last($releases);
            } elseif ($current !== null && preg_match('/^- (.+)$/', $line, $m)) {
                $releases[$current]['lines'][] = $m[1];
            }
        }

        return $releases;
    }
}
