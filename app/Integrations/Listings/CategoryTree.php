<?php

namespace App\Integrations\Listings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A CHANNEL'S CATEGORIES AS A TREE, ONE LEVEL AT A TIME.
 *
 * Every channel already stores its categories with a parent, so the tree is
 * in the database and nothing here calls a marketplace. What differs is only
 * the spelling, so each channel's columns are named once below rather than
 * five pickers being written.
 *
 * DEPTH IS NEVER ASSUMED. Shopee reaches five levels, Lazada six, TikTok
 * five, and 70% of Lazada's pickable categories sit deeper than three, so a
 * caller asks for children until a node says it is a leaf.
 *
 * DEPTH IS ALSO NOT READ FROM A `level` COLUMN. Some tiers hold 0 in every
 * row of it, which would render an entire tree as roots. The parent chain is
 * the only trustworthy source, and it is what `path()` walks.
 */
final class CategoryTree
{
    /**
     * How each channel spells the same tree.
     *
     * table  - where the categories live
     * id     - the marketplace's own id for a category
     * parent - the id of the category above it
     * leaf   - the column that says nothing is below, or null when the
     *          channel keeps no such flag and it has to be counted
     * store  - the column naming the store, for a channel whose categories
     *          are the store's own rather than the marketplace's
     * root   - what the parent column holds at the top of the tree
     *
     * @var array<string, array<string, mixed>>
     */
    private const SHAPES = [
        'shopee' => ['table' => 'shopee_categories', 'id' => 'category_id', 'parent' => 'parent_id', 'leaf' => 'leaf', 'store' => null, 'root' => 0],
        'lazada' => ['table' => 'lazada_categories', 'id' => 'category_id', 'parent' => 'parent_id', 'leaf' => 'leaf', 'store' => null, 'root' => 0],
        'tiktok' => ['table' => 'tiktok_categories', 'id' => 'id', 'parent' => 'parent_id', 'leaf' => 'is_leaf', 'store' => null, 'root' => null],
        'venta' => ['table' => 'venta_categories', 'id' => 'venta_category_id', 'parent' => 'parent_id', 'leaf' => null, 'store' => 'venta_setting_id', 'root' => 0],
        'woocommerce' => ['table' => 'woocommerce_categories', 'id' => 'woo_category_id', 'parent' => 'parent_id', 'leaf' => null, 'store' => 'woocommerce_setting_id', 'root' => 0],
    ];

    /** How far a parent chain is followed before it is called a loop. */
    private const MAX_DEPTH = 12;

    public static function knows(string $channel): bool
    {
        return isset(self::SHAPES[$channel]) && Schema::hasTable(self::SHAPES[$channel]['table']);
    }

    /**
     * One level of the tree: the children of $parentId, or the roots when it
     * is null. Each row says whether anything sits below it, so the caller
     * knows to open another column or to stop.
     *
     * @return list<array{id: int, name: string, leaf: bool}>
     */
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

        /* A channel that keeps no leaf flag is asked the same question by
           counting: a category nothing points at is the end of its branch. */
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

    /**
     * The chain from the root down to this category, the category last. An
     * id the channel does not hold answers with an empty list, which is how
     * a listing pointing at a category the store no longer has reads.
     *
     * @return list<array{id: int, name: string, leaf: bool}>
     */
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
                /* A chain that cannot reach the root is not half a path: the
                   caller would open columns under a parent that is not there. */
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

        // A parent that points back into its own chain: answer nothing rather than loop.
        return [];
    }

    /**
     * Categories whose name holds $q, leaves only, each with the readable
     * path down to it. What the picker's search box shows: only a leaf can
     * be confirmed, so only leaves are worth offering.
     *
     * @return list<array{id: int, name: string, path: string}>
     */
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
            /* A channel that keeps no leaf flag is asked by counting: a
               category nothing points at is the end of its branch. */
            : $query->whereNotIn($s['id'], self::scoped($channel, $storeId)->distinct()->pluck($s['parent'])->filter()->all());

        $rows = $query->orderBy('name')->limit(max(1, min(50, $limit)))->get([$s['id'] . ' as id', 'name']);

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'name' => (string) $r->name,
            'path' => implode(' / ', array_column(self::path($channel, $storeId, (int) $r->id), 'name')),
        ])->values()->all();
    }

    /** This channel's rows, narrowed to the store when its categories are the store's own. */
    private static function scoped(string $channel, int $storeId)
    {
        $s = self::SHAPES[$channel];
        $query = DB::table($s['table']);

        return $s['store'] === null ? $query : $query->where($s['store'], $storeId);
    }

    /** @param array<string, mixed> $s */
    private static function whereRoot($query, array $s): void
    {
        $s['root'] === null
            ? $query->whereNull($s['parent'])
            : $query->where(fn ($q) => $q->where($s['parent'], $s['root'])->orWhereNull($s['parent']));
    }
}
