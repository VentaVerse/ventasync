<?php

namespace Extensions\opencart\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OrderController;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderStatus;
use Extensions\opencart\Models\OpenCartSetting;
use Illuminate\Http\Request;

/**
 * Per-store order list for an OpenCart-connected storefront.
 *
 * This used to live in core App\Http\Controllers\OrderController as
 * "opencartIndex()" — moved here as part of the decoupling work so core
 * has no awareness of OpenCart-specific routes or views.
 */
class OpenCartOrderController extends Controller
{
    public function index(Request $request, int $store)
    {
        $q = trim((string) $request->get('q', ''));
        $statusId = (int) $request->get('status', 0);

        $sortable = ['order_id', 'date_added', 'total', 'firstname'];
        $sort = in_array($request->get('sort'), $sortable) ? $request->get('sort') : 'date_added';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $orders = Order::query()
            ->with(['products', 'latestHistory'])
            ->where('marketplace_source', 'opencart:' . $store)
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

        // The store's own name, read for the one row this page is about
        // rather than by pulling every store's name into an array to index
        // one of them. The legacy view used the full map for a Store column
        // whose value was identical on every row; the column is gone.
        $storeLabel = (string) (OpenCartSetting::where('id', $store)->value('store_name') ?: ('OpenCart #' . $store));

        return view('ext-opencart::orders.index', [
            'orders'        => $orders,
            'statuses'      => $statuses,
            'productImages' => $productImages,
            'lazadaImages'  => $lazadaImages,
            'sort'          => $sort,
            'dir'           => $dir,
            'storeId'       => $store,
            'storeLabel'    => $storeLabel,
            'filters'       => ['q' => $q, 'status' => $statusId],
        ]);
    }
}
