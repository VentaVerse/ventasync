<?php

namespace App\Channels\Listings;

use Illuminate\Support\Facades\DB;

final class TemplateUsage
{
    public static function description(string $channel, int $storeId, array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }
        $listings = [];
        DB::table('ch_' . $channel . '_listings')->where('store_id', $storeId)
            ->where(fn ($q) => $q->whereIn('description_prefix_id', $ids)->orWhereIn('description_suffix_id', $ids))
            ->orderBy('id')
            ->select(['id', 'description_prefix_id', 'description_suffix_id'])
            ->chunk(1000, function ($rows) use (&$listings) {
                foreach ($rows as $row) {
                    foreach (array_unique([(int) $row->description_prefix_id, (int) $row->description_suffix_id]) as $id) {
                        $listings[$id] = ($listings[$id] ?? 0) + 1;
                    }
                }
            });

        return self::shape($ids, ['listing' => $listings]);
    }

    public static function watermark(string $channel, int $storeId, array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }

        return self::shape($ids, [
            'listing' => self::counts('ch_' . $channel . '_listings', 'watermark_template_id', $storeId, $ids),
            'listing template' => self::counts('ch_' . $channel . '_listing_templates', 'watermark_template_id', $storeId, $ids),
        ]);
    }

    public static function listingTemplate(string $channel, int $storeId, array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }

        return self::shape($ids, [
            'product group' => self::counts('ch_' . $channel . '_product_groups', 'listing_template_id', $storeId, $ids),
        ]);
    }

    public static function sentence(array $usage): string
    {
        $parts = [];
        foreach ($usage as $noun => $n) {
            if ($n > 0) {
                $parts[] = number_format($n) . ' ' . \Illuminate\Support\Str::plural($noun, $n);
            }
        }

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            default => implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts),
        };
    }

    private static function counts(string $table, string $column, int $storeId, array $ids): array
    {
        return DB::table($table)->where('store_id', $storeId)->whereIn($column, $ids)
            ->groupBy($column)->selectRaw($column . ' as tid, count(*) as n')
            ->pluck('n', 'tid')->mapWithKeys(fn ($n, $id) => [(int) $id => (int) $n])->all();
    }

    private static function shape(array $ids, array $byNoun): array
    {
        $out = [];
        foreach ($ids as $id) {
            foreach ($byNoun as $noun => $counts) {
                $out[$id][$noun] = (int) ($counts[$id] ?? 0);
            }
        }

        return $out;
    }

    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
    }
}
