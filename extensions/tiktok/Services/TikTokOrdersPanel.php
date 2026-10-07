<?php

namespace Extensions\tiktok\Services;

use App\Support\Fulfilment\CourierTally;
use App\Support\FulfilmentSteps;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Http\Request;

class TikTokOrdersPanel
{
    private array $tabStatusMap = [
        'UNPAID'           => ['UNPAID'],
        'TO_SHIP'          => ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'],
        'IN_TRANSIT'       => ['IN_TRANSIT'],
        'DELIVERED'        => ['DELIVERED'],
        'COMPLETED'        => ['COMPLETED'],
        'CANCELLED'        => ['CANCELLED'],
    ];

    public function build(Request $request, ?int $storeId = null): array
    {
        $setting = $storeId !== null
            ? TikTokSetting::query()->find($storeId)
            : TikTokSetting::defaultStore();
        if ($storeId !== null && $setting !== null) {
            app()->instance('tiktok.route-store', $setting);
            \Illuminate\Support\Facades\URL::defaults(['store' => (int) $setting->id]);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'placed_from' => ['nullable', 'date'],
            'placed_to' => ['nullable', 'date', 'after_or_equal:placed_from'],
            'courier' => ['nullable', 'string', 'max:80'],
        ]);

        $courierKey = trim((string) ($filters['courier'] ?? ''));

        $find = trim((string) ($filters['q'] ?? ''));
        $placedFrom = trim((string) ($filters['placed_from'] ?? ''));
        $placedTo = trim((string) ($filters['placed_to'] ?? ''));

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        $tab = strtoupper((string) $request->query('tab', 'TO_SHIP'));
        $allowedTabs = ['ALL', 'UNPAID', 'TO_SHIP', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED', 'CANCELLED'];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'ALL';
        }

        $pendingSubtab = (string) $request->query('pending_sub', $tab === 'TO_SHIP' ? 'to_pack' : '');
        if ($tab !== 'TO_SHIP') {
            $pendingSubtab = '';
        }

        $tabs = FulfilmentSteps::tabs([
            'ALL'          => 'all',
            'UNPAID'       => 'unpaid',
            'TO_SHIP'      => 'to_ship',
            'IN_TRANSIT'   => 'shipping',
            'DELIVERED'    => 'delivered',
            'COMPLETED'    => 'completed',
            'CANCELLED'    => 'cancelled',
        ]);

        $defaultDir = ($tab === 'TO_SHIP') ? 'asc' : 'desc';
        $sortKey = strtolower((string) $request->query('sort', ''));
        if ($sortKey !== '') {
            session(['marketplace_sort.tiktok' => $sortKey]);
        } else {
            $sortKey = strtolower((string) session('marketplace_sort.tiktok', ''));
        }
        if (!in_array($sortKey, ['created_asc', 'created_desc', 'ship_by_asc', 'ship_by_desc'], true)) {
            $sortKey = 'created_' . $defaultDir;
        }
        $sortDir = str_ends_with($sortKey, '_asc') ? 'asc' : 'desc';
        $sortBy = str_starts_with($sortKey, 'ship_by') ? 'ship_by' : 'created';

        $query = TikTokOrder::query()->with('products');
        $baseCountQuery = TikTokOrder::query();

        if ($tab !== 'ALL') {
            if ($tab === 'TO_SHIP' && $pendingSubtab !== '') {
                $subStatuses = match ($pendingSubtab) {
                    'to_pack'     => ['AWAITING_SHIPMENT'],
                    'to_handover' => ['AWAITING_COLLECTION'],
                    default       => ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'],
                };
                $query->whereIn('status', $subStatuses);
            } else {
                $statuses = $this->tabStatusMap[$tab] ?? [];
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        }

        if ($find !== '') {
            $like = '%' . $find . '%';

            $query->where(function ($w) use ($like) {
                $w->where('order_id', 'like', $like)
                    ->orWhere('buyer_name', 'like', $like)
                    ->orWhere('raw->tracking_number', 'like', $like)
                    ->orWhere('raw->line_items[0]->tracking_number', 'like', $like)
                    ->orWhereHas('products', function ($p) use ($like) {
                        $p->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('variation', 'like', $like);
                    });
            });
        }

        if ($placedFrom !== '') {
            $query->whereDate('order_created_at', '>=', $placedFrom);
        }
        if ($placedTo !== '') {
            $query->whereDate('order_created_at', '<=', $placedTo);
        }

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($this->tabStatusMap as $k => $statuses) {
            $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
        }
        $tab_counts['to_pack'] = (clone $baseCountQuery)->whereIn('status', ['AWAITING_SHIPMENT'])->count();
        $tab_counts['to_handover'] = (clone $baseCountQuery)->whereIn('status', ['AWAITING_COLLECTION'])->count();

        $subStepStatuses = ['to_pack' => 'AWAITING_SHIPMENT', 'to_handover' => 'AWAITING_COLLECTION'];
        $courierTally = new CourierTally();
        if ($tab === 'TO_SHIP' && isset($subStepStatuses[$pendingSubtab])) {
            TikTokOrder::query()
                ->where('status', $subStepStatuses[$pendingSubtab])
                ->select(['id', 'raw'])
                ->orderBy('id')
                ->chunk(300, function ($orders) use ($courierTally) {
                    foreach ($orders as $ho) {
                        $hoRaw = is_array($ho->raw) ? $ho->raw : [];
                        $courier = (string) ($hoRaw['shipping_provider'] ?? $hoRaw['shipping_provider_name'] ?? '');
                        if ($courier === '' && !empty($hoRaw['line_items'])) {
                            $courier = (string) (($hoRaw['line_items'][0] ?? [])['shipping_provider_name'] ?? '');
                        }
                        $courierTally->add((int) $ho->id, $courier);
                    }
                });

            if ($courierKey !== '') {
                $courierIds = $courierTally->idsFor($courierKey);
                if ($courierIds !== null) {
                    $query->whereIn('id', $courierIds);
                }
            }
        }

        if ($sortBy === 'ship_by') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.tts_sla_time')) AS UNSIGNED) {$sortDir}");
        }
        $orders = $query
            ->orderBy('order_created_at', $sortDir)
            ->orderBy('created_at', $sortDir)
            ->paginate($perPage)
            ->appends($request->query());

        return [
            'orders'        => $orders,
            'per_page'      => $perPage,
            'tabs'          => $tabs,
            'active_tab'    => $tab,
            'pending_sub'   => $pendingSubtab,
            'tab_counts'    => $tab_counts,
            'sort'          => $sortKey,
            'last_result'   => session('tiktok_orders_last_result'),
            'courier_tally' => $courierTally->forStrip($courierKey),
            'filters'       => [
                'q' => $find,
                'courier' => $courierKey,
                'placed_from' => $placedFrom,
                'placed_to' => $placedTo,
            ],
        ];
    }
}
