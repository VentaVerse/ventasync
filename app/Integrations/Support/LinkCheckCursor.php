<?php

namespace App\Integrations\Support;

use Illuminate\Support\Facades\Cache;

/**
 * "Run it again to continue" made true (the owner, 2026-09-07: "Why 130 is
 * left out?"). Every link check looks at two hundred products per run,
 * because a longer run inside one web request hits php-fpm's execution
 * limit, and every caller handed it the first two hundred by product id
 * every time, so the rest were never reached. The caller now hands the
 * list rotated to start after the last product the previous run checked,
 * so successive runs walk the whole store and then start over.
 */
final class LinkCheckCursor
{
    /** How many products a link check reads per run, on every channel that caps. */
    public const LIMIT = 200;

    /**
     * @param string $key one per channel and store, e.g. "shopee:3"
     * @param list<int> $ids every linked product id
     * @return list<int> the same ids, starting after the last one checked
     */
    public static function rotate(string $key, array $ids, int $limit = self::LIMIT): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === []) {
            return [];
        }
        $cacheKey = 'link-check-cursor:' . $key;
        $after = (int) Cache::get($cacheKey, 0);
        $start = 0;
        foreach ($ids as $i => $id) {
            if ($id > $after) {
                $start = $i;
                break;
            }
            if ($i === count($ids) - 1) {
                $start = 0; // every id is at or before the cursor: the walk is complete, start over
            }
        }
        $rotated = array_merge(array_slice($ids, $start), array_slice($ids, 0, $start));
        if (count($rotated) > $limit) {
            Cache::put($cacheKey, $rotated[$limit - 1], now()->addDay());
        } else {
            Cache::forget($cacheKey); // one run covers the store; the next starts from the top
        }

        return $rotated;
    }
}
