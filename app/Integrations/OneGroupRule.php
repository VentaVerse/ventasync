<?php

namespace App\Integrations;

use Illuminate\Support\Facades\DB;

/**
 * One product, one group (cleanup point 2, the owner 2026-09-03).
 *
 * A product in several groups was the ambiguity that made group status
 * unreadable. Every membership write goes through claim(): products
 * already owned by another group ON THE SAME STORE are HELD and their
 * owner named - never silently reassigned - unless the caller passes
 * them in $moveIds, which is the operator's explicit "Move here". A store
 * never sees another store's groups, so a product may sit in one group
 * on each store.
 *
 * The pivots carry no store, so a caller passes the group table's store
 * column. Pedallion, a single-store channel, passes none and also keeps a
 * DB unique on its pivot's product_id; for every other channel this
 * class is the only gate.
 */
class OneGroupRule
{
    /**
     * @param array $productIds ids the caller wants in $groupId
     * @param array $moveIds subset explicitly approved to move
     * @return array{free: int[], held: array<int, string>, moved: array<int, string>}
     *   free: not owned elsewhere - insert them;
     *   held: product_id => owning group name - do NOT insert, tell the operator;
     *   moved: product_id => former group name - old membership already deleted, insert them.
     */
    public static function claim(
        string $pivotTable,
        string $groupFk,
        string $groupTable,
        ?string $storeFk,
        int $groupId,
        array $productIds,
        array $moveIds = [],
    ): array {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $moveIds = array_map('intval', $moveIds);

        if ($productIds === []) {
            return ['free' => [], 'held' => [], 'moved' => []];
        }

        $owners = self::owners($pivotTable, $groupFk, $groupTable, $storeFk, $groupId, $productIds);

        $free = [];
        $held = [];
        $moved = [];

        foreach ($productIds as $pid) {
            if (! isset($owners[$pid])) {
                $free[] = $pid;
                continue;
            }
            if (in_array($pid, $moveIds, true)) {
                DB::table($pivotTable)
                    ->where('product_id', $pid)
                    ->where($groupFk, $owners[$pid]['group_id'])
                    ->delete();
                $moved[$pid] = $owners[$pid]['name'];
                continue;
            }
            $held[$pid] = $owners[$pid]['name'];
        }

        return ['free' => $free, 'held' => $held, 'moved' => $moved];
    }

    /**
     * Who owns each product, excluding $groupId itself and scoped to
     * $groupId's own store where groups are store-scoped.
     *
     * @return array<int, array{group_id: int, name: string}>
     */
    public static function owners(
        string $pivotTable,
        string $groupFk,
        string $groupTable,
        ?string $storeFk,
        int $groupId,
        array $productIds,
    ): array {
        if ($productIds === []) {
            return [];
        }

        $q = DB::table($pivotTable . ' as p')
            ->join($groupTable . ' as g', 'g.id', '=', 'p.' . $groupFk)
            ->whereIn('p.product_id', array_map('intval', $productIds))
            ->where('p.' . $groupFk, '!=', $groupId);

        if ($storeFk !== null) {
            $storeId = DB::table($groupTable)->where('id', $groupId)->value($storeFk);
            // A group id that resolves no store (0 on a create form) means
            // no scope to narrow by; unscoped beats matching nothing.
            if ($storeId !== null) {
                $q->where('g.' . $storeFk, $storeId);
            }
        }

        $out = [];
        foreach ($q->get(['p.product_id', 'p.' . $groupFk . ' as group_id', 'g.name']) as $r) {
            $out[(int) $r->product_id] = ['group_id' => (int) $r->group_id, 'name' => (string) $r->name];
        }

        return $out;
    }

    /** The held-products clause every flash shares. */
    public static function heldClause(array $held): string
    {
        if ($held === []) {
            return '';
        }

        $parts = [];
        foreach (array_slice($held, 0, 3, true) as $pid => $name) {
            $parts[] = "#{$pid} is in '{$name}'";
        }
        $more = count($held) - min(3, count($held));

        return ' ' . count($held) . ' not added - a product lives in one group: '
            . implode('; ', $parts) . ($more > 0 ? "; and {$more} more" : '')
            . '. Use Move here to transfer one.';
    }
}
