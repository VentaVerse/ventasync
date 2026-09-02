<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductLink extends Model
{
    use \Extensions\shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'shopee_product_links';

    protected $fillable = [
        'shopee_setting_id',
        'product_id',
        'shopee_item_id',
        'shopee_model_id',
        'sku',
        'live_status',
        'live_checked_at',
        'last_synced_at',
        'last_sync_action',
        'last_sync_ok',
        'last_sync_error_code',
        'last_sync_error_message',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'live_checked_at' => 'datetime',
        'last_sync_ok' => 'boolean',
    ];

    /**
     * The mirrored bucket counts, keyed the way the listings strip names
     * them. Linked products only: the tab table shows nothing else, so
     * the count must not promise more rows than the tab has.
     *
     * @param  array<string, string>  $tabMap  tab key => Shopee item_status
     * @return array<string, int>
     */
    public static function mirrorCounts(array $tabMap): array
    {
        $byStatus = static::query()
            ->whereNotNull('live_status')
            ->selectRaw('live_status, COUNT(DISTINCT product_id) as n')
            ->groupBy('live_status')
            ->pluck('n', 'live_status');

        $out = [];
        foreach ($tabMap as $tabKey => $status) {
            $out[$tabKey] = (int) ($byStatus[$status] ?? 0);
        }

        return $out;
    }

    /**
     * Break a product's link to Shopee, everywhere it is recorded.
     *
     * A product's Shopee item id is stored in THREE tables, and that is the
     * whole reason leftovers survived an unlink:
     *
     *   shopee_product_links           the link itself, what push tests
     *   shopee_product_group_products  the group page's own copy of the id
     *   shopee_item_cache              the sku -> item id map used to relink
     *
     * Each unlink action used to clear a different subset. The products page
     * cleared the link and the cache but left the pivot holding the id, so the
     * group page went on displaying a Shopee id for a product that was no
     * longer linked to anything. The group page cleared none of them and only
     * set a status column, so it reported success over a link that was still
     * there and push kept skipping the product as already linked.
     *
     * So all three are cleared here, in one place, and neither caller decides
     * any part of it. An id left in any one of the three is enough to bring the
     * link back: the cache in particular is a sku lookup with no notion of
     * which shop it was filled from, so a stale row silently re-attaches the
     * product to the item it was just detached from.
     *
     * Unlinking is global rather than per group, because the link it breaks is
     * global. A product linked to one Shopee item is not linked to it in one
     * group and unlinked in another.
     *
     * Returns the number of link rows removed.
     */
    public static function unlinkProduct(int $productId): int
    {
        $links = static::query()->where('product_id', $productId)->get();

        foreach ($links as $link) {
            // Keyed on item AND sku so a different product sharing the item
            // keeps its own cache row.
            ShopeeItemCache::query()
                ->where('shopee_item_id', $link->shopee_item_id)
                ->where('sku', $link->sku)
                ->delete();
        }

        $removed = static::query()->where('product_id', $productId)->delete();

        // Runs even when there was no link. A pivot can hold an id the link
        // table has already lost - that is exactly the state this bug left
        // behind - and refusing to clear it because the link is missing would
        // preserve the leftover forever.
        ShopeeProductGroupProduct::query()
            ->where('product_id', $productId)
            ->update([
                'shopee_item_id' => null,
                'sync_status' => 'unlinked',
                'push_error' => null,
            ]);

        return $removed;
    }
}
