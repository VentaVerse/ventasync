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
            'reverse_order_id' => ['nullable', 'string', 'max:80'],
            'trade_order_id' => ['nullable', 'string', 'max:80'],
        ]);

        $reverseOrderId = trim((string)($filters['reverse_order_id'] ?? ''));
        $tradeOrderId = trim((string)($filters['trade_order_id'] ?? ''));

        $perPage = (int)$request->query('per_page', 10);
        if (!in_array($perPage, [10,20,50], true)) { $perPage = 10; }

        // Tab logic
        $tab = strtoupper((string)$request->query('tab', 'ALL'));
        $allowedTabs = ['ALL', 'RETURN_INITIATED', 'IN_PROGRESS', 'DISPUTE', 'REFUND_ISSUED', 'CLOSED', 'REJECTED'];
        if (!in_array($tab, $allowedTabs, true)) { $tab = 'ALL'; }

        $tabStatusMap = [
            'RETURN_INITIATED' => ['return initiated', 'return_initiated', 'requested', 'pending'],
            'IN_PROGRESS'      => ['in progress', 'in_progress', 'processing', 'approved', 'return_in_progress', 'shipped_back', 'received'],
            'DISPUTE'          => ['dispute in progress', 'dispute_in_progress', 'dispute'],
            'REFUND_ISSUED'    => ['refund issued', 'refund_issued', 'refund_paid', 'refunded'],
            'CLOSED'           => ['closed', 'completed'],
            'REJECTED'         => ['rejected', 'cancelled', 'canceled'],
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

        // Apply tab filter
        if ($tab !== 'ALL') {
            $statuses = $tabStatusMap[$tab] ?? [];
            if (!empty($statuses)) {
                $query->where(function ($q) use ($statuses) {
                    foreach ($statuses as $s) {
                        $q->orWhereRaw('LOWER(reverse_status) = ?', [strtolower($s)]);
                    }
                });
            }
        }

        if ($reverseOrderId !== '') {
            $query->where('reverse_order_id', 'like', '%' . $reverseOrderId . '%');
        }
        if ($tradeOrderId !== '') {
            $query->where('trade_order_id', 'like', '%' . $tradeOrderId . '%');
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

        $orders = $query
            ->orderByDesc('updated_at')
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
            'filters' => [
                'reverse_order_id' => $reverseOrderId,
                'trade_order_id' => $tradeOrderId,
            ],
        ];
    }
}
