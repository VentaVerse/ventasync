<?php

namespace Extensions\ventacart\Services;

use Illuminate\Support\Facades\DB;
use App\Support\FulfilmentSteps;
use Extensions\ventacart\Models\VentaCartOrder;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartStatusPlacements;
use Illuminate\Http\Request;

class VentaCartOrdersPanel
{
    private array $sortable = [
        'catalog_order_id',
        'ventacart_order_id',
        'ventacart_order_number',
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
        $status = $request->filled('status') ? (string) $request->input('status') : '';

        $statusCounts = VentaCartOrder::where('ventacart_setting_id', $storeId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $buckets = FulfilmentSteps::bucket('ventacart.orders', $statusCounts, VentaCartStatusPlacements::resolver($storeId));

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

        $statusStep = $status !== '' ? (VentaCartStatusPlacements::stepFor($storeId, $status) ?? 'other') : null;

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

        $defaultDir = $tab === 'TO_SHIP' ? 'asc' : 'desc';

        $dir = in_array($request->input('dir'), ['asc', 'desc'], true)
            ? (string) $request->input('dir')
            : $defaultDir;

        $query = VentaCartOrder::where('ventacart_setting_id', $storeId)
            ->with('products')
            ->orderBy($sort, $dir);

        if ($tab === 'TO_SHIP') {
            $query->whereIn('status', $buckets[$pendingSubtab]['statuses']);
        } elseif ($tab === 'OTHER') {
            $query->whereIn('status', $buckets['other']['statuses']);
        } elseif ($tab !== 'ALL') {
            $query->whereIn('status', $buckets[$tabToStep[$tab]]['statuses']);
        }

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
            $tab_counts[$key] = $step === 'to_ship'
                ? $pending_sub_counts['to_pack'] + $pending_sub_counts['to_handover']
                : $buckets[$step]['count'];
        }

        if ($buckets['other']['count'] > 0 || $tab === 'OTHER') {
            $tabs['OTHER'] = FulfilmentSteps::label('other');
            $tab_counts['OTHER'] = $buckets['other']['count'];
        }

        $q = $request->filled('q') ? (string) $request->input('q') : '';
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('ventacart_order_id', $q)
                    ->orWhere('ventacart_order_number', $q)
                    ->orWhere('tracking_number', 'like', "%{$q}%")

                    ->orWhereHas('products', function ($p) use ($q) {
                        $p->where('name', 'like', "%{$q}%")
                            ->orWhere('sku', 'like', "%{$q}%");
                    });
            });
        }

        $placedFrom = trim((string) $request->input('placed_from', ''));
        $placedTo = trim((string) $request->input('placed_to', ''));
        if ($placedFrom !== '') {
            $query->whereDate('order_created_at', '>=', $placedFrom);
        }
        if ($placedTo !== '') {
            $query->whereDate('order_created_at', '<=', $placedTo);
        }

        $perPage = (int) $request->query('per_page', 10);
        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        $orders = $query->paginate($perPage)->withQueryString();

        $vtImages = $this->imagesForOrders($orders->getCollection());

        $storeLabel = (string) (VentaCartSetting::where('id', $storeId)->value('store_name')
            ?: ('VentaCart #' . $storeId));

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

        $resolved = \App\Support\CatalogImages::forSkus($skus);

        $linked = [];
        $linkIds = DB::table('ventacart_product_links')
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
