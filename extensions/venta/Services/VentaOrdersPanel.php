<?php

namespace Extensions\venta\Services;

use Illuminate\Support\Facades\DB;
use App\Support\FulfilmentSteps;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Http\Request;

/**
 * Assembles the view data for one Venta store's orders list: the status
 * filter, the search, sort persistence via the request's own sort/dir pair,
 * the per-status counts the segment bar is built from, and pagination.
 * Shared by that store's seller center (VentaOrderController@index) and the
 * fulfilment hub, both of which render ext-venta::orders._panel with this
 * array. Extracted from VentaOrderController@index without any logic change.
 *
 * The one difference from the three single-store panels
 * (Extensions\shopee\Services\ShopeeOrdersPanel and its Lazada and TikTok
 * twins): build() takes a store id, because every query here is scoped to
 * one store. The seller center passes the {store} route param; the hub
 * parses it out of the channel key it was asked for ("venta:2"), which it
 * only reaches after that key has matched a tab the user can actually see.
 */
class VentaOrdersPanel
{
    /**
     * Columns the list may be ordered by. Moved verbatim from the
     * controller, which was the only reader of it. Anything else falls back
     * to the order date, so a hand-typed sort= can never reach the query.
     */
    private array $sortable = [
        'catalog_order_id',
        'venta_order_id',
        'venta_order_number',
        'customer_name',
        'status',
        'payment_method',
        'total',
        'tracking_number',
        'order_created_at',
    ];

    public function build(Request $request, int $storeId): array
    {
        $sort = in_array($request->input('sort'), $this->sortable, true)
            ? (string) $request->input('sort')
            : 'order_created_at';
        /* FIRST IN, FIRST OUT ON THE WORK QUEUE.
         *
         * This always defaulted to newest first, so the oldest unshipped
         * order - the one closest to breaching - sat at the bottom of the
         * list behind everything that arrived after it. The marketplace
         * panels already flip to oldest-first on their work tab and keep
         * newest-first elsewhere; this now does the same.
         *
         * Filtered to a status nobody acts on (Delivered, Cancelled), newest
         * first is still right: that is a record being consulted, not a
         * queue being worked. */
        $status = $request->filled('status') ? (string) $request->input('status') : '';

        /* THE STRIP IS FIXED, LIKE EVERY MARKETPLACE'S (the owner, 2026-08-28).
         *
         * This used to render whatever statuses the store had sent, in the
         * order the lifecycle map put them, so a step with nothing in it
         * simply did not exist on the strip and the strip changed shape
         * from store to store. The three marketplace panels show the same
         * fixed row (All, Unpaid, To Ship, Shipping, Delivered, Cancelled,
         * Failed Delivery), keep a tab at zero clickable, and land on To
         * Ship with To Pack open. A packer crossing from Shopee to the
         * storefront now finds the queue where their hands expect it.
         *
         * Counts for the whole store come from one GROUP BY over status,
         * and every placement below is read off that one result, so the
         * strip, the sub strip and the list filter cannot disagree about
         * where a status sits. */
        $statusCounts = VentaOrder::where('venta_setting_id', $storeId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $buckets = FulfilmentSteps::bucket('venta.orders', $statusCounts);

        $tabToStep = [
            'ALL'             => 'all',
            'UNPAID'          => 'unpaid',
            'TO_SHIP'         => 'to_ship',
            'SHIPPING'        => 'shipping',
            'DELIVERED'       => 'delivered',
            'CANCELLED'       => 'cancelled',
            'FAILED_DELIVERY' => 'failed',
        ];

        $tabs = FulfilmentSteps::tabs($tabToStep);

        $tab = strtoupper((string) $request->query('tab', ''));

        /* status= STILL WORKS, AS AN ALIAS.
         *
         * Every link that existed before this strip (the hub's card links,
         * bookmarks, the seller center's own old URLs) named a raw status.
         * Rather than 404 them or silently land them on To Ship, the status
         * picks the tab it belongs to and narrows that tab's list to itself,
         * which is exactly what the old link showed. */
        $statusStep = $status !== '' ? (FulfilmentSteps::stepFor('venta.orders', $status) ?? 'other') : null;

        if ($tab === '') {
            $tab = match ($statusStep) {
                null => 'TO_SHIP',
                'to_pack', 'to_handover' => 'TO_SHIP',
                default => (string) (array_search($statusStep, $tabToStep, true) ?: 'OTHER'),
            };
        }

        if (! isset($tabToStep[$tab]) && $tab !== 'OTHER') {
            $tab = 'TO_SHIP';
        }

        // The sub step only means anything inside To Ship. A status alias
        // that names a To Handover status opens To Handover, so the old
        // "?status=Start Pickup" link lands on the parcels it always showed.
        $pendingSubtab = '';
        if ($tab === 'TO_SHIP') {
            $pendingSubtab = (string) $request->query(
                'pending_sub',
                $statusStep === 'to_handover' ? 'to_handover' : 'to_pack'
            );
            if (! in_array($pendingSubtab, ['to_pack', 'to_handover'], true)) {
                $pendingSubtab = 'to_pack';
            }
        }

        // First in, first out on the work queue, newest first everywhere
        // else. Same rule as the marketplace panels, keyed on the tab now
        // rather than on a hand-kept list of status names.
        $defaultDir = $tab === 'TO_SHIP' ? 'asc' : 'desc';

        $dir = in_array($request->input('dir'), ['asc', 'desc'], true)
            ? (string) $request->input('dir')
            : $defaultDir;

        // Line items are rendered per order (one table row each, with the
        // order-level facts rowspanned beside them), so they are loaded with
        // the page rather than lazily per row.
        $query = VentaOrder::where('venta_setting_id', $storeId)
            ->with('products')
            ->orderBy($sort, $dir);

        // The tab's statuses, spelled the way the store spelled them, which
        // is what the rows hold. An empty list is a tab with nothing in it,
        // and whereIn on an empty list matches nothing, which is right.
        if ($tab === 'TO_SHIP') {
            $query->whereIn('status', $buckets[$pendingSubtab]['statuses']);
        } elseif ($tab === 'OTHER') {
            $query->whereIn('status', $buckets['other']['statuses']);
        } elseif ($tab !== 'ALL') {
            $query->whereIn('status', $buckets[$tabToStep[$tab]]['statuses']);
        }

        // filled() is false for both a missing param and an empty one, so
        // neither narrows the list. Same guard the page has always used.
        if ($status !== '') {
            $query->where('status', $status);
        }

        $pending_sub_counts = [
            'to_pack'     => $buckets['to_pack']['count'],
            'to_handover' => $buckets['to_handover']['count'],
        ];

        $tab_counts = ['ALL' => (int) $statusCounts->sum()];
        foreach ($tabToStep as $key => $step) {
            if ($step === 'all') {
                continue;
            }
            // To Ship counts both of its steps, so the tab can never read
            // zero above a sub tab that has work in it.
            $tab_counts[$key] = $step === 'to_ship'
                ? $pending_sub_counts['to_pack'] + $pending_sub_counts['to_handover']
                : $buckets[$step]['count'];
        }

        // Other is defined by exclusion, so it only appears when the store
        // has sent something the map does not place. Same rule as Shopee's.
        if ($buckets['other']['count'] > 0 || $tab === 'OTHER') {
            $tabs['OTHER'] = FulfilmentSteps::label('other');
            $tab_counts['OTHER'] = $buckets['other']['count'];
        }

        $q = $request->filled('q') ? (string) $request->input('q') : '';
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('venta_order_id', $q)
                    ->orWhere('venta_order_number', $q)
                    ->orWhere('tracking_number', 'like', "%{$q}%")

                    /* Product, which this search has never covered. Every
                       marketplace list matches it and this one did not, so
                       the one question a caller actually asks - "the patch
                       cable, sometime last week" - worked on four channels
                       and failed on this one. */
                    ->orWhereHas('products', function ($p) use ($q) {
                        $p->where('name', 'like', "%{$q}%")
                            ->orWhere('sku', 'like', "%{$q}%");
                    });
            });
        }

        /* When the order was placed. The marketplace lists carry this pair
           and this one did not, so a storefront order could be found by name
           but never narrowed by date. Same column the list sorts by. */
        $placedFrom = trim((string) $request->input('placed_from', ''));
        $placedTo = trim((string) $request->input('placed_to', ''));
        if ($placedFrom !== '') {
            $query->whereDate('order_created_at', '>=', $placedFrom);
        }
        if ($placedTo !== '') {
            $query->whereDate('order_created_at', '<=', $placedTo);
        }

        /* Page size was fixed at 50 while every other channel offers 10, 20
           or 50 and defaults to 10. On a phone that made this the tallest
           page in the product by a factor of five - 138 orders in one scroll,
           about 32,000px - and the operator had no way to shorten it. */
        $perPage = (int) $request->query('per_page', 10);
        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        $orders = $query->paginate($perPage)->withQueryString();

        // Product thumbnails, resolved from the local catalog.
        //
        // The storefront's order payload carries no image: it ships an id, a
        // name, a sku and a price. The marketplaces do carry one, so their
        // rows had a picture and these did not, on a surface whose whole
        // point is that the four read the same way. A packer identifies what
        // to pull off the shelf by looking at it.
        //
        // The catalog is the right source anyway. These products were
        // pushed to the storefront FROM here, so the ERP holds the image the
        // storefront is showing, and one lookup keyed by sku covers a page.
        $vtImages = $this->imagesForOrders($orders->getCollection());

        // Read for the one store this panel is about. On the hub the tab
        // already knows the label, but the panel is also rendered by the
        // seller center, so it resolves its own rather than depending on a
        // caller to hand one in.
        $storeLabel = (string) (VentaSetting::where('id', $storeId)->value('store_name')
            ?: ('Venta #' . $storeId));

        return [
            'orders'             => $orders,
            'tabs'               => $tabs,
            'active_tab'         => $tab,
            'pending_subtab'     => $pendingSubtab,
            'tab_counts'         => $tab_counts,
            'pending_sub_counts' => $pending_sub_counts,
            'sort'               => $sort,
            'dir'                => $dir,
            'storeId'            => $storeId,
            'storeLabel'         => $storeLabel,
            'per_page'           => $perPage,
            'vtImages'           => $vtImages,
            'filters'            => [
                'q' => $q,
                'status' => $status,
                'placed_from' => $placedFrom,
                'placed_to' => $placedTo,
            ],
        ];
    }

    /**
     * sku => image path, for every product on this page of orders.
     *
     * Three ways in, tried in order of how much they actually mean:
     *
     *   1. product.sku      the same identifier the storefront was given
     *   2. product.model    OpenCart's own identifier, which some of this
     *                       catalog uses where sku is blank
     *   3. venta_product_links  the explicit mapping, which is the most
     *                       correct of the three and also the sparsest
     *
     * Measured against the current data, sku reaches 78 of 113 distinct
     * skus, model another 21, and the link table 3 more that neither found.
     * The rest have no catalog image at all, and render the same "No
     * image" the marketplace panels render for the same absence.
     *
     * One query per source per page, never per row: this is a list that
     * routinely shows a hundred lines.
     *
     * @param  \Illuminate\Support\Collection<int, VentaOrder>  $orders
     * @return array<string, string>
     */
    private function imagesForOrders($orders): array
    {
        $skus = [];

        foreach ($orders as $order) {
            foreach ($order->products ?? [] as $product) {
                $sku = trim((string) $product->sku);

                if ($sku !== '') {
                    $skus[$sku] = true;
                }
            }
        }

        if ($skus === []) {
            return [];
        }

        $skus = array_keys($skus);

        /* The shared resolver (live fix 2026-08-26): a variation SKU used to
         * find nothing here, because only the product's own sku and model
         * were looked up, so every variation row read "No image" on the
         * one channel whose payload carries none. CatalogImages resolves a
         * combination's own picture, then the parent product's. */
        $resolved = \App\Support\CatalogImages::forSkus($skus);

        // Storefront links name the product outright; they answer for any
        // SKU the catalog lookup could not.
        $linked = [];
        $linkIds = DB::table('venta_product_links')
            ->whereIn('sku', $skus)
            ->pluck('product_id', 'sku')
            ->all();

        if ($linkIds !== []) {
            $linkImages = DB::table('product')
                ->whereIn('product_id', array_values($linkIds))
                ->where('image', '!=', '')
                ->whereNotNull('image')
                ->pluck('image', 'product_id')
                ->all();

            foreach ($linkIds as $sku => $productId) {
                if (isset($linkImages[$productId])) {
                    $linked[(string) $sku] = $linkImages[$productId];
                }
            }
        }

        return $resolved + $linked;
    }
}
