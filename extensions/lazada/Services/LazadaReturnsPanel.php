<?php

namespace Extensions\lazada\Services;

use Extensions\lazada\Models\LazadaReverseOrder;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Http\Request;

/**
 * Assembles the view data for the Lazada returns list: filters, tab counts,
 * pagination, and the two lookups this channel needs that Shopee's does not
 * (the ERP order number behind a reverse order, and a product image resolved
 * by SKU because the return payload carries none).
 *
 * The counterpart of LazadaOrdersPanel, extracted for the same reason: one
 * returns list has to render both in the channel workspace and in place on
 * the fulfilment hub, so that "Returns and refunds" stops throwing the
 * operator into a different shell.
 *
 * Lifted from LazadaOrderController@returns without a logic change.
 */
class LazadaReturnsPanel
{
    public function build(Request $request): array
    {
        $setting = LazadaSetting::query()->first();
        $region = $setting->region ?? 'ph';

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'opened_from' => ['nullable', 'date'],
            'opened_to' => ['nullable', 'date', 'after_or_equal:opened_from'],
        ]);

        // One field matching return number, order number or product - the
        // same box the Shopee returns list carries, and for the reason set
        // out there. No buyer term, because this channel's payload carries
        // only a buyer_id.
        $find = trim((string)($filters['q'] ?? ''));
        $openedFrom = trim((string)($filters['opened_from'] ?? ''));
        $openedTo = trim((string)($filters['opened_to'] ?? ''));

        /* The same four orderings the Shopee returns list offers, over the
           same two ideas: when the return was opened, and when it last moved.
           There is no buyer search here to match Shopee's, because Lazada's
           reverse order payload carries only a buyer_id - a number the
           operator has never seen and could not type. A search box over it
           would be worse than its absence. */
        $sortKey = strtolower((string)$request->query('sort', ''));
        $allowedSort = ['opened_desc', 'opened_asc', 'updated_desc', 'updated_asc'];
        if (!in_array($sortKey, $allowedSort, true)) { $sortKey = 'updated_desc'; }

        $perPage = (int)$request->query('per_page', 10);
        if (!in_array($perPage, [10,20,50], true)) { $perPage = 10; }

        // Tab logic
        $tab = strtoupper((string)$request->query('tab', 'ALL'));
        $allowedTabs = ['ALL', 'RETURN_INITIATED', 'IN_PROGRESS', 'DISPUTE', 'REFUND_ISSUED', 'CLOSED', 'REJECTED', 'OTHER'];
        if (!in_array($tab, $allowedTabs, true)) { $tab = 'ALL'; }

        // The values on the RIGHT are what Lazada actually stores, not what
        // its documentation lists. Written from the docs, this map matched
        // none of the 76 rows in the table: every tab read 0 while All read
        // 76, because the live vocabulary is REFUND_SUCCESS, REQUEST_CANCEL
        // and REFUND_REJECT and none of the three appeared here. The
        // documented spellings are kept alongside them - Lazada is free to
        // send either - and the OTHER tab below now catches whatever comes
        // next instead of dropping it out of the strip entirely.
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
            'RETURN_INITIATED' => 'Return Initiated',
            'IN_PROGRESS'      => 'Return in Progress',
            'DISPUTE'          => 'Dispute in Progress',
            'REFUND_ISSUED'    => 'Refund Issued',
            'CLOSED'           => 'Closed',
            'REJECTED'         => 'Rejected',
        ];

        $query = LazadaReverseOrder::query()
            ->where('region', $region);

        $baseCountQuery = LazadaReverseOrder::query()->where('region', $region);

        // Everything the map does not name, so a status Lazada invents is
        // reachable instead of silently absent from every tab.
        $mappedStatuses = array_values(array_unique(array_merge(...array_values($tabStatusMap))));

        // Apply tab filter
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

            /* PRODUCT SEARCH ON A CHANNEL THAT SENDS NO PRODUCT NAME.
             *
             * A Lazada reverse order line names its product only as
             * product_sku and seller_sku_id. There is no title anywhere in
             * the payload, so "flat patch cable" cannot be matched against
             * the return itself at all.
             *
             * It can be matched one step out. The same two sources this
             * panel already reads to put a thumbnail on the row - the synced
             * order products, and the ERP catalog by SKU - both carry names,
             * so the search runs there first and comes back with the SKUs
             * those names belong to. Those SKUs are what the returns are
             * then matched on.
             *
             * Capped, because a one-letter term would otherwise resolve to
             * every SKU in the catalog and build a WHERE clause the length of
             * the product table. A term matching more than 200 products is
             * not a search anyone meant to run. */
            $skus = collect(\DB::table('lazada_order_products')
                ->where('name', 'like', $like)
                ->limit(400)
                ->pluck('sku'));

            $pfx = (string) config('catalog.prefix');

            $skus = $skus->merge(\DB::table($pfx . 'product_description as pd')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'pd.product_id')
                ->where('pd.name', 'like', $like)
                ->limit(400)
                ->pluck('p.sku'));

            $skus = $skus->merge(\DB::table($pfx . 'product_description as pd')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'pd.product_id')
                ->join($pfx . 'product_option_value as pov', 'pov.product_id', '=', 'p.product_id')
                ->where('pd.name', 'like', $like)
                ->limit(400)
                ->pluck('pov.sku'));

            $skus = $skus->filter()->unique()->take(200)->values();

            $query->where(function ($w) use ($like, $skus) {
                $w->where('reverse_order_id', 'like', $like)
                    ->orWhere('trade_order_id', 'like', $like)
                    // A SKU or a reason typed straight in, and the only path
                    // for a return whose order was never synced.
                    ->orWhere('items', 'like', $like);

                // The SKUs the name search resolved to. Quoted, so a SKU
                // matches as a whole JSON value rather than as a fragment of
                // a longer one.
                foreach ($skus as $sku) {
                    $w->orWhere('items', 'like', '%"' . $sku . '"%');
                }
            });
        }

        /* return_created_at is when Lazada opened the return, lifted out of
           the raw payload by the migration that added the column. Rows whose
           raw never carried it are null and fall outside any range, which is
           the honest answer to "opened between these dates". */
        if ($openedFrom !== '') {
            $query->whereDate('return_created_at', '>=', $openedFrom);
        }
        if ($openedTo !== '') {
            $query->whereDate('return_created_at', '<=', $openedTo);
        }

        // Tab counts
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

        // Map each reverse order's trade_order_id -> linked ERP catalog order id
        // (via lazada_orders) so the list can show the ERP # like Shopee.
        $tradeIds = collect($orders->items())->pluck('trade_order_id')->filter()->unique()->values();
        $erpMap = $tradeIds->isEmpty() ? collect() : \DB::table('lazada_orders')
            ->whereIn('order_id', $tradeIds->all())
            ->whereNotNull('catalog_order_id')
            ->pluck('catalog_order_id', 'order_id');

        // Product images aren't in the return payload — resolve them by seller SKU
        // from the synced order products (same image regardless of order).
        $skus = collect($orders->items())
            ->flatMap(fn ($o) => collect(is_array($o->items) ? $o->items : [])
                ->map(fn ($it) => $it['seller_sku_id'] ?? ($it['product']['product_sku'] ?? null)))
            ->filter()->unique()->values();
        $imgMap = $skus->isEmpty() ? collect() : \DB::table('lazada_order_products')
            ->whereIn('sku', $skus->all())
            ->whereNotNull('image')->where('image', '!=', '')
            ->pluck('image', 'sku');

        // Catalog fallback: many returns are for orders that were never synced,
        // so resolve missing images from the ERP product catalog by SKU (product
        // SKU or option-value SKU -> parent product image). Catalog images live
        // on the local storage disk. Order-product images (marketplace URLs) win.
        if ($skus->isNotEmpty()) {
            $pfx = (string) config('catalog.prefix');
            $catImg = \DB::table($pfx . 'product')
                ->whereIn('sku', $skus->all())
                ->whereNotNull('image')->where('image', '!=', '')
                ->pluck('image', 'sku');
            $ovImg = \DB::table($pfx . 'product_option_value as pov')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'pov.product_id')
                ->whereIn('pov.sku', $skus->all())
                ->whereNotNull('p.image')->where('p.image', '!=', '')
                ->pluck('p.image', 'pov.sku');
            $catImgMap = $catImg->merge($ovImg)->map(fn ($img) => asset('storage/' . ltrim($img, '/')));
            $imgMap = $catImgMap->merge($imgMap); // order-product image wins; catalog fills gaps
        }

        return [
            'setting' => $setting,
            'orders' => $orders,
            'erpMap' => $erpMap,
            'imgMap' => $imgMap,
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
