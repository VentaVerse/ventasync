<?php

namespace Extensions\opencart\Services;

use App\Http\Controllers\Sales\OrderController;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderStatus;
use Extensions\opencart\Models\OpenCartSetting;
use Illuminate\Http\Request;

class OpenCartOrdersPanel
{
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
                        ->orWhere('order_id', '=', (int) $q > 0 ? (int) $q : 0)
                        ->orWhere('tracking_number', 'like', '%' . $q . '%')
                        ->orWhere('tracking', 'like', '%' . $q . '%')
                        ->orWhereHas('products', function ($p) use ($q) {
                            $p->where('name', 'like', '%' . $q . '%')->orWhere('model', 'like', '%' . $q . '%');
                        });
                });
            })
            ->when($statusId > 0, function ($query) use ($statusId) {
                $query->where('order_status_id', $statusId);
            })
            ->orderBy($sort, $dir)
            ->paginate(50)
            ->withQueryString();

        $productImages = OrderController::resolveOrderProductImages($orders->getCollection());
        $lazadaImages = OrderController::resolveLazadaImages($orders->getCollection());

        $statuses = OrderStatus::orderBy('order_status_id')->get();

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
