<?php

namespace App\Integrations\Listings;

final class SheetReads
{
    public const PER_RUN = 40;

    public const PER_PRESS = 10;

    public static function run(array $wanted, array $cached, callable $read, int $limit = self::PER_RUN): array
    {
        $ids = fn (array $list) => array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), $list),
            fn ($v) => $v !== '' && $v !== '0'
        )));
        $missing = array_values(array_diff($ids($wanted), $ids($cached)));
        shuffle($missing);
        $batch = array_slice($missing, 0, max(0, $limit));

        $done = 0;
        $failed = 0;
        foreach ($batch as $id) {
            try {
                $ok = (bool) $read($id);
            } catch (\Throwable) {
                $ok = false;
            }
            $ok ? $done++ : $failed++;
        }
        $left = count($missing) - count($batch);

        return ['read' => $done, 'failed' => $failed, 'left' => $left, 'summary' => self::summary($done, $failed, $left)];
    }

    public static function summary(int $read, int $failed, int $left): string
    {
        $parts = [];
        if ($read > 0) {
            $parts[] = 'Read ' . self::sheets($read) . '.';
        }
        if ($failed > 0) {
            $parts[] = self::sheets($failed) . ' could not be read.';
        }
        if ($left > 0) {
            $parts[] = self::sheets($left) . ' left for the next refresh.';
        }

        return implode(' ', $parts);
    }

    private static function sheets(int $n): string
    {
        return number_format($n) . ' attribute ' . ($n === 1 ? 'sheet' : 'sheets');
    }
}
