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
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';

        // Line items are rendered per order (one table row each, with the
        // order-level facts rowspanned beside them), so they are loaded with
        // the page rather than lazily per row.
        $query = VentaOrder::where('venta_setting_id', $storeId)
            ->with('products')
            ->orderBy($sort, $dir);

        // filled() is false for both a missing param and an empty one, so
        // neither narrows the list. Same guard the page has always used.
        $status = $request->filled('status') ? (string) $request->input('status') : '';
        if ($status !== '') {
            $query->where('status', $status);
        }

        $q = $request->filled('q') ? (string) $request->input('q') : '';
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('venta_order_id', $q)
                    ->orWhere('venta_order_number', $q)
                    ->orWhere('tracking_number', 'like', "%{$q}%");
            });
        }

        $orders = $query->paginate(50)->withQueryString();

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
            'filters'      => ['q' => $q, 'status' => $status],
        ];
    }
}
