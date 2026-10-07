<?php

namespace App\Integrations\Listings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CategoryTree
{
    private const SHAPES = [
        'shopee' => ['table' => 'shopee_categories', 'id' => 'category_id', 'parent' => 'parent_id', 'leaf' => 'leaf', 'store' => null, 'root' => 0],
        'lazada' => ['table' => 'lazada_categories', 'id' => 'category_id', 'parent' => 'parent_id', 'leaf' => 'leaf', 'store' => null, 'root' => 0],
        'tiktok' => ['table' => 'tiktok_categories', 'id' => 'id', 'parent' => 'parent_id', 'leaf' => 'is_leaf', 'store' => null, 'root' => null],
        'ventacart' => ['table' => 'ventacart_categories', 'id' => 'ventacart_category_id', 'parent' => 'parent_id', 'leaf' => null, 'store' => 'ventacart_setting_id', 'root' => 0],
        'woocommerce' => ['table' => 'woocommerce_categories', 'id' => 'woo_category_id', 'parent' => 'parent_id', 'leaf' => null, 'store' => 'woocommerce_setting_id', 'root' => 0],
    ];

    private const MAX_DEPTH = 12;

    public static function knows(string $channel): bool
    {
        return isset(self::SHAPES[$channel]) && Schema::hasTable(self::SHAPES[$channel]['table']);
    }

    public static function children(string $channel, int $storeId, ?int $parentId = null): array
    {
        if (! self::knows($channel)) {
            return [];
        }
        $s = self::SHAPES[$channel];

        $query = self::scoped($channel, $storeId)->select([$s['id'] . ' as id', 'name']);
        if ($s['leaf'] !== null) {
            $query->addSelect($s['leaf'] . ' as leaf');
        }

        $parentId === null || $parentId <= 0
            ? self::whereRoot($query, $s)
            : $query->where($s['parent'], $parentId);

        $rows = $query->orderBy('name')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $parents = $s['leaf'] === null
            ? self::scoped($channel, $storeId)
                ->whereIn($s['parent'], $rows->pluck('id')->all())
                ->distinct()->pluck($s['parent'])->map(fn ($v) => (int) $v)->all()
            : [];

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'name' => (string) $r->name,
            'leaf' => $s['leaf'] === null
                ? ! in_array((int) $r->id, $parents, true)
                : (bool) $r->leaf,
        ])->values()->all();
    }

    public static function path(string $channel, int $storeId, int $categoryId): array
    {
        if (! self::knows($channel) || $categoryId <= 0) {
            return [];
        }
        $s = self::SHAPES[$channel];

        $chain = [];
        $current = $categoryId;

        for ($step = 0; $step < self::MAX_DEPTH; $step++) {
            $select = [$s['id'] . ' as id', 'name', $s['parent'] . ' as parent'];
            if ($s['leaf'] !== null) {
                $select[] = $s['leaf'] . ' as leaf';
            }
            $row = self::scoped($channel, $storeId)->where($s['id'], $current)->first($select);
            if ($row === null) {
                return $chain === [] ? [] : [];
            }

            array_unshift($chain, [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'leaf' => $s['leaf'] === null ? true : (bool) $row->leaf,
            ]);

            $parent = $row->parent;
            if ($parent === null || (int) $parent === 0 || (int) $parent === (int) $s['root']) {
                return $chain;
            }
            $current = (int) $parent;
        }

        return [];
    }

    public static function search(string $channel, int $storeId, string $q, int $limit = 25): array
    {
        $q = trim($q);
        if (! self::knows($channel) || $q === '') {
            return [];
        }
        $s = self::SHAPES[$channel];

        $query = self::scoped($channel, $storeId)
            ->where(function ($w) use ($q, $s) {
                $w->where('name', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%');
                if (ctype_digit($q)) {
                    $w->orWhere($s['id'], (int) $q);
                }
            });

        $s['leaf'] !== null
            ? $query->where($s['leaf'], 1)
            : $query->whereNotIn($s['id'], self::scoped($channel, $storeId)->distinct()->pluck($s['parent'])->filter()->all());

        $rows = $query->orderBy('name')->limit(max(1, min(50, $limit)))->get([$s['id'] . ' as id', 'name']);

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'name' => (string) $r->name,
            'path' => implode(' / ', array_column(self::path($channel, $storeId, (int) $r->id), 'name')),
        ])->values()->all();
    }

    private static function scoped(string $channel, int $storeId)
    {
        $s = self::SHAPES[$channel];
        $query = DB::table($s['table']);

        return $s['store'] === null ? $query : $query->where($s['store'], $storeId);
    }

    private static function whereRoot($query, array $s): void
    {
        $s['root'] === null
            ? $query->whereNull($s['parent'])
            : $query->where(fn ($q) => $q->where($s['parent'], $s['root'])->orWhereNull($s['parent']));
    }
}
