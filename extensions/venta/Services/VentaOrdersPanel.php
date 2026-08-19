<?php

namespace Extensions\venta\Services;

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

        $vtWorkStatuses = ['Pending', 'For Verification', 'Processing', 'Start Pickup'];
        $defaultDir = in_array($status, $vtWorkStatuses, true) ? 'asc' : 'desc';

        $dir = in_array($request->input('dir'), ['asc', 'desc'], true)
            ? (string) $request->input('dir')
            : $defaultDir;

        // Line items are rendered per order (one table row each, with the
        // order-level facts rowspanned beside them), so they are loaded with
        // the page rather than lazily per row.
        $query = VentaOrder::where('venta_setting_id', $storeId)
            ->with('products')
            ->orderBy($sort, $dir);

        // filled() is false for both a missing param and an empty one, so
        // neither narrows the list. Same guard the page has always used;
        // $status itself is resolved above, because the default sort
        // direction depends on it.
        if ($status !== '') {
            $query->where('status', $status);
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
        if (! in_array($perPage, [10, 20, 50], true)) {
            $perPage = 10;
        }

        $orders = $query->paginate($perPage)->withQueryString();

        // Counts for the segment bar, over the whole store rather than the
        // filtered list: a count that moved when you clicked it would be
        // telling you about the filter, not about the store.
        $statusCounts = VentaOrder::where('venta_setting_id', $storeId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        // Read for the one store this panel is about. On the hub the tab
        // already knows the label, but the panel is also rendered by the
        // seller center, so it resolves its own rather than depending on a
        // caller to hand one in.
        $storeLabel = (string) (VentaSetting::where('id', $storeId)->value('store_name')
            ?: ('Venta #' . $storeId));

        return [
            'orders'       => $orders,
            'statusCounts' => $statusCounts,
            'sort'         => $sort,
            'dir'          => $dir,
            'storeId'      => $storeId,
            'storeLabel'   => $storeLabel,
            'per_page'     => $perPage,
            'filters'      => [
                'q' => $q,
                'status' => $status,
                'placed_from' => $placedFrom,
                'placed_to' => $placedTo,
            ],
        ];
    }
}
