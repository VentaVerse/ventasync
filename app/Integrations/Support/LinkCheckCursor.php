<?php

namespace App\Integrations\Support;

use Illuminate\Support\Facades\Cache;

final class LinkCheckCursor
{
    public const LIMIT = 200;

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
                $start = 0;
            }
        }
        $rotated = array_merge(array_slice($ids, $start), array_slice($ids, 0, $start));
        if (count($rotated) > $limit) {
            Cache::put($cacheKey, $rotated[$limit - 1], now()->addDay());
        } else {
            Cache::forget($cacheKey);
        }

        return $rotated;
    }
}
