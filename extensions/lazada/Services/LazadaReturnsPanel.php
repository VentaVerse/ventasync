<?php

namespace Extensions\Lazada\Services;

use Extensions\Lazada\Models\LazadaReverseOrder;
use Extensions\Lazada\Models\LazadaStore;
use Illuminate\Http\Request;

class LazadaReturnsPanel
{
    public function build(Request $request, ?int $storeId = null): array
    {
        $store = $storeId !== null
            ? LazadaStore::query()->find($storeId)
            : LazadaStore::defaultStore();
        if ($storeId !== null && $store !== null) {
            app()->instance('lazada.route-store', $store);
            \Illuminate\Support\Facades\URL::defaults(['store' => (int) $store->id]);
        }
        $region = $store->region ?? 'ph';

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'opened_from' => ['nullable', 'date'],
            'opened_to' => ['nullable', 'date', 'after_or_equal:opened_from'],
        ]);

        $find = trim((string)($filters['q'] ?? ''));
        $openedFrom = trim((string)($filters['opened_from'] ?? ''));
        $openedTo = trim((string)($filters['opened_to'] ?? ''));

        $sortKey = strtolower((string)$request->query('sort', ''));
        $allowedSort = ['opened_desc', 'opened_asc', 'updated_desc', 'updated_asc'];
        if (!in_array($sortKey, $allowedSort, true)) { $sortKey = 'updated_desc'; }

        $perPage = (int)$request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50, 100], true)) { $perPage = 10; }

        $tab = strtoupper((string)$request->query('tab', 'ALL'));
        $allowedTabs = ['ALL', 'RETURN_INITIATED', 'IN_PROGRESS', 'DISPUTE', 'REFUND_ISSUED', 'CLOSED', 'REJECTED', 'OTHER'];
        if (!in_array($tab, $allowedTabs, true)) { $tab = 'ALL'; }

        $tabStatusMap = [
            'RETURN_INITIATED' => ['return initiated', 'return_initiated', 'requested', 'pending'],
            'IN_PROGRESS'      => ['in progress', 'in_progress', 'processing', 'approved', 'return_in_progress', 'shipped_back', 'received'],
            'DISPUTE'          => ['dispute in progress', 'dispute_in_progress', 'dispute'],
            'REFUND_ISSUED'    => ['refund issued', 'refund_issued', 'refund_paid', 'refunded', 'refund_success'],
            'CLOSED'           => ['closed', 'completed'],
            'REJECTED'         => ['rejected', 'cancelled', 'canceled', 'request_cancel', 'refund_reject'],
        ];

        $tabs = [
            'ALL'              => 'All',
            'RETURN_INITIATED' => 'Return started',
            'IN_PROGRESS'      => 'Package returning',
            'DISPUTE'          => 'Disputed',
            'REFUND_ISSUED'    => 'Refunded',
            'CLOSED'           => 'Closed',
            'REJECTED'         => 'Rejected',
        ];

        $query = LazadaReverseOrder::query()
            ->where('region', $region);

        $baseCountQuery = LazadaReverseOrder::query()->where('region', $region);

        $mappedStatuses = array_values(array_unique(array_merge(...array_values($tabStatusMap))));

        if ($tab === 'OTHER') {
            $query->whereRaw('LOWER(reverse_status) NOT IN (' . implode(',', array_fill(0, count($mappedStatuses), '?')) . ')',
                array_map('strtolower', $mappedStatuses));
        } elseif ($tab !== 'ALL') {
            $statuses = $tabStatusMap[$tab] ?? [];
            if (!empty($statuses)) {
                $query->where(function ($q) use ($statuses) {
                    foreach ($statuses as $s) {
                        $q->orWhereRaw('LOWER(reverse_status) = ?', [strtolower($s)]);
                    }
                });
            }
        }

        if ($find !== '') {
            $like = '%' . $find . '%';

            $skus = collect(\DB::table('ch_lazada_order_products')
                ->where('name', 'like', $like)
                ->limit(400)
                ->pluck('sku'));

            $skus = $skus->merge(\DB::table('product_descriptions as pd')
                ->join('products as p', 'p.product_id', '=', 'pd.product_id')
                ->where('pd.name', 'like', $like)
                ->limit(400)
                ->pluck('p.sku'));

            $skus = $skus->merge(\DB::table('product_descriptions as pd')
                ->join('products as p', 'p.product_id', '=', 'pd.product_id')
                ->join('product_variation_values as pov', 'pov.product_id', '=', 'p.product_id')
                ->where('pd.name', 'like', $like)
                ->limit(400)
                ->pluck('pov.sku'));

            $skus = $skus->filter()->unique()->take(200)->values();

            $query->where(function ($w) use ($like, $skus) {
                $w->where('reverse_order_id', 'like', $like)
                    ->orWhere('trade_order_id', 'like', $like)
                    ->orWhere('items', 'like', $like);

                foreach ($skus as $sku) {
                    $w->orWhere('items', 'like', '%"' . $sku . '"%');
                }
            });
        }

        if ($openedFrom !== '') {
            $query->whereDate('return_created_at', '>=', $openedFrom);
        }
        if ($openedTo !== '') {
            $query->whereDate('return_created_at', '<=', $openedTo);
        }

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            $tab_counts[$k] = (clone $baseCountQuery)->where(function ($q) use ($statuses) {
                foreach ($statuses as $s) {
                    $q->orWhereRaw('LOWER(reverse_status) = ?', [strtolower($s)]);
                }
            })->count();
        }

        $otherCount = (clone $baseCountQuery)
            ->whereRaw('LOWER(reverse_status) NOT IN (' . implode(',', array_fill(0, count($mappedStatuses), '?')) . ')',
                array_map('strtolower', $mappedStatuses))
            ->count();
        if ($otherCount > 0) {
            $tabs['OTHER'] = 'Other';
            $tab_counts['OTHER'] = $otherCount;
        }

        match ($sortKey) {
            'opened_asc'  => $query->orderBy('return_created_at'),
            'opened_desc' => $query->orderByDesc('return_created_at'),
            'updated_asc' => $query->orderBy('updated_at'),
            default       => $query->orderByDesc('updated_at'),
        };

        $orders = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        $tradeIds = collect($orders->items())->pluck('trade_order_id')->filter()->unique()->values();
        $erpMap = $tradeIds->isEmpty() ? collect() : \DB::table('ch_lazada_orders')
            ->whereIn('order_id', $tradeIds->all())
            ->whereNotNull('catalog_order_id')
            ->when(app()->bound('lazada.route-store'), fn ($q) => $q->where('store_id', app('lazada.route-store')->id))
            ->pluck('catalog_order_id', 'order_id');

        $skus = collect($orders->items())
            ->flatMap(fn ($o) => collect(is_array($o->items) ? $o->items : [])
                ->map(fn ($it) => $it['seller_sku_id'] ?? ($it['product']['product_sku'] ?? null)))
            ->filter()->unique()->values();
        $imgMap = $skus->isEmpty() ? collect() : \DB::table('ch_lazada_order_products')
            ->whereIn('sku', $skus->all())
            ->whereNotNull('image')->where('image', '!=', '')
            ->pluck('image', 'sku');

        if ($skus->isNotEmpty()) {
            $catImg = \DB::table('products')
                ->whereIn('sku', $skus->all())
                ->whereNotNull('image')->where('image', '!=', '')
                ->pluck('image', 'sku');
            $ovImg = \DB::table('product_variation_values as pov')
                ->join('products as p', 'p.product_id', '=', 'pov.product_id')
                ->whereIn('pov.sku', $skus->all())
                ->whereNotNull('p.image')->where('p.image', '!=', '')
                ->pluck('p.image', 'pov.sku');
            $catImgMap = $catImg->merge($ovImg)->map(fn ($img) => \App\Services\Media\ImageCache::url($img));
            $imgMap = $catImgMap->merge($imgMap);
        }

        $nameMap = collect();

        if ($skus->isNotEmpty()) {

            $catName = \DB::table('products as p')
                ->join('product_descriptions as pd', 'pd.product_id', '=', 'p.product_id')
                ->whereIn('p.sku', $skus->all())
                ->whereNotNull('pd.name')->where('pd.name', '!=', '')
                ->pluck('pd.name', 'p.sku');

            $ovName = \DB::table('product_variation_values as pov')
                ->join('products as p', 'p.product_id', '=', 'pov.product_id')
                ->join('product_descriptions as pd', 'pd.product_id', '=', 'p.product_id')
                ->whereIn('pov.sku', $skus->all())
                ->whereNotNull('pd.name')->where('pd.name', '!=', '')
                ->pluck('pd.name', 'pov.sku');

            $orderName = \DB::table('ch_lazada_order_products')
                ->whereIn('sku', $skus->all())
                ->whereNotNull('name')->where('name', '!=', '')
                ->pluck('name', 'sku');

            $nameMap = $catName->merge($ovName)->merge($orderName);
        }

        return [
            'setting' => $store,
            'orders' => $orders,
            'erpMap' => $erpMap,
            'imgMap' => $imgMap,
            'nameMap' => $nameMap,
            'per_page' => $perPage,
            'last_result' => session('lazada_orders_last_result'),
            'tabs' => $tabs,
            'active_tab' => $tab,
            'tab_counts' => $tab_counts,
            'sort' => $sortKey,
            'filters' => [
                'q' => $find,
                'opened_from' => $openedFrom,
                'opened_to' => $openedTo,
            ],
        ];
    }
}
