<?php

namespace Extensions\opencart\Services;

use App\Http\Controllers\Sales\OrderController;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderStatus;
use Extensions\opencart\Models\OpenCartSetting;
use Illuminate\Http\Request;

/**
 * Assembles the view data for one OpenCart storefront's orders list: the
 * search, the status filter, sorting, the shared image resolution and
 * pagination. Shared by that store's seller center
 * (OpenCartOrderController@index) and the fulfilment hub, both of which
 * render ext-opencart::orders._panel with this array. Extracted from
 * OpenCartOrderController@index without any logic change.
 *
 * The rows are core catalog orders, not a channel table of their own: the
 * OpenCart sync writes straight into the ERP's order tables and tags them
 * with marketplace_source = "opencart:{id}", which is the whole of this
 * panel's store scoping.
 *
 * Same store-id signature as Extensions\venta\Services\VentaOrdersPanel and
 * the same reason for it: every query here belongs to one store.
 */
class OpenCartOrdersPanel
{
    /** Moved verbatim from the controller, which was the only reader. */
    private array $sortable = ['order_id', 'date_added', 'total', 'firstname'];

    public function build(Request $request, int $storeId): array
    {
        $q = trim((string) $request->get('q', ''));
        $statusId = (int) $request->get('status', 0);

        $sort = in_array($request->get('sort'), $this->sortable, true)
            ? (string) $request->get('sort')
            : 'date_added';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $orders = Order::query()
            ->with(['products', 'latestHistory'])
            ->where('marketplace_source', 'opencart:' . $storeId)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('firstname', 'like', '%' . $q . '%')
                        ->orWhere('lastname', 'like', '%' . $q . '%')
                        ->orWhere('email', 'like', '%' . $q . '%')
                        ->orWhere('marketplace_order_id', 'like', '%' . $q . '%')
                        ->orWhere('order_id', '=', (int) $q > 0 ? (int) $q : 0);
                });
            })
            ->when($statusId > 0, function ($query) use ($statusId) {
                $query->where('order_status_id', $statusId);
            })
            ->orderBy($sort, $dir)
            ->paginate(50)
            ->withQueryString();

        // Reuse the shared image-resolution pipeline so per-store views stay
        // visually consistent with the unified Orders index.
        $productImages = OrderController::resolveOrderProductImages($orders->getCollection());
        $lazadaImages = OrderController::resolveLazadaImages($orders->getCollection());

        $statuses = OrderStatus::orderBy('order_status_id')->get();

        // The store's own name, read for the one row this panel is about
        // rather than by pulling every store's name into an array to index
        // one of them.
        $storeLabel = (string) (OpenCartSetting::where('id', $storeId)->value('store_name')
            ?: ('OpenCart #' . $storeId));

        return [
            'orders'        => $orders,
            'statuses'      => $statuses,
            'productImages' => $productImages,
            'lazadaImages'  => $lazadaImages,
            'sort'          => $sort,
            'dir'           => $dir,
            'storeId'       => $storeId,
            'storeLabel'    => $storeLabel,
            'filters'       => ['q' => $q, 'status' => $statusId],
        ];
    }
}
