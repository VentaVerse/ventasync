<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A product's marketplace status, written to every group that holds it.
 *
 * sync_status and push_error live on the group pivot, one row per group per
 * product. But whether a product is on the marketplace is a GLOBAL fact: one
 * link, one remote product, one truth. Storing a global fact per group meant
 * the same product read differently depending on which page you opened.
 *
 * Demonstrated rather than assumed: product 1799 sits in Venta groups 4 and 8.
 * Marking group 4 as failed, then pushing successfully from group 8, left
 * group 4 reading "failed - Category not found" permanently. The product was
 * fine. Group 4 had simply never been told.
 *
 * Both directions are dangerous and the quiet one is worse. A stale error
 * sends the operator chasing a problem that no longer exists; a stale SUCCESS
 * hides a real failure behind a green badge on another page, and that one gets
 * ignored.
 *
 * This is the only way to write that status, deliberately. Fanning out to
 * every group is not a rule for authors to remember - a rule like that is
 * forgotten by the fourth caller, which is how this codebase ended up with one
 * concept living in three places twice in a week.
 */
class ChannelProductStatus
{
    /**
     * Write one product's status to every group of the given channel.
     *
     * @param  string      $pivotTable   e.g. venta_product_group_products
     * @param  string      $groupColumn  e.g. venta_product_group_id
     * @param  int[]|null  $groupIds     the groups this write may touch; null
     *                                   means every group of the channel,
     *                                   which is correct for a single-store
     *                                   channel. A multi-store channel MUST
     *                                   pass its store's groups: a failure
     *                                   against one shop says nothing about
     *                                   another.
     * @param  array<string, mixed>  $attributes  the columns to set
     */
    public static function write(
        string $pivotTable,
        string $groupColumn,
        ?array $groupIds,
        int $productId,
        array $attributes
    ): int {
        if ($groupIds !== null && empty($groupIds)) {
            return 0;
        }

        if (array_key_exists('push_error', $attributes) && is_string($attributes['push_error'])) {
            $attributes['push_error'] = Str::limit($attributes['push_error'], 480);
        }

        $query = DB::table($pivotTable)->where('product_id', $productId);

        if ($groupIds !== null) {
            $query->whereIn($groupColumn, $groupIds);
        }

        return $query->update($attributes);
    }
}
