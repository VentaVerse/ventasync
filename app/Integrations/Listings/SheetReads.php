<?php

namespace App\Integrations\Listings;

/**
 * Reads the attribute sheets a store's listings need and has never read.
 * Readiness cannot judge a listing whose category's sheet is unread, so
 * Refresh and the scheduled refresh read them here: a bounded number per
 * run, in shuffled order, so a category the marketplace keeps refusing
 * never holds up the rest.
 */
final class SheetReads
{
    /** The scheduled refresh. */
    public const PER_RUN = 40;

    /** The Refresh button, which already walks the shop in the same request. */
    public const PER_PRESS = 10;

    /**
     * @param  array<int|string>  $wanted  every category the store's listings and groups use
     * @param  array<int|string>  $cached  the ones already read
     * @param  callable(string): bool  $read  reads one sheet; true when it was stored
     * @return array{read: int, failed: int, left: int, summary: string}
     */
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
