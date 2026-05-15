<?php

namespace Extensions\shopee;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\LayoutBannerContributor;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
use App\Integrations\Contracts\MobileMarketplaceProvider;
use App\Integrations\Contracts\OrderFeesContributor;
use App\Integrations\Contracts\RootRouteHandler;
use App\Integrations\Contracts\SkuSyncContributor;
use App\Models\Catalog\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ShopeeExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor, DashboardContributor, OrderFeesContributor, MobileMarketplaceProvider, LayoutBannerContributor, MarketplaceSourceOptionsProvider, RootRouteHandler
{
    private const MOBILE_TAB_STATUS_MAP = [
        'UNPAID'              => ['UNPAID'],
        'PENDING'             => ['READY_TO_SHIP', 'PROCESSED'],
        'SHIPPING'            => ['SHIPPED'],
        'DELIVERED_COMPLETED' => ['COMPLETED'],
        'CANCELLED'           => ['CANCELLED', 'IN_CANCEL'],
        'FAILED_DELIVERY'     => ['RETRY_SHIP'],
        'RETURN'              => ['AWAITING_RETURN', 'IN_RETURN', 'RETURNED'],
    ];

    private const MOBILE_PENDING_SUB_MAP = [
        'to_pack'     => ['READY_TO_SHIP'],
        'to_handover' => ['PROCESSED'],
    ];

    protected string $id = 'shopee';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\shopee\Commands\ShopeePushStock::class,
                \Extensions\shopee\Commands\ShopeeRefreshToken::class,
                \Extensions\shopee\Commands\ShopeeSyncOrders::class,
            ]);
        }

        $this->app->make(IntegrationRegistry::class)->register($this);
    }

    public function integrationId(): string
    {
        return $this->id;
    }

    public function integrationCards(): array
    {
        return [
            new IntegrationCard(
                id: $this->id,
                name: 'Shopee',
                tagline: 'Shopee marketplace integration — OAuth, products, orders, returns.',
                icon: 'shopee',
                accent: '#ee4d2d',
                permission: 'manage_shopee',
                menu: [
                    new MenuItem('Settings', 'ext.shopee.index', 'manage_shopee'),
                    new MenuItem('Categories', 'ext.shopee.categories.index', 'manage_shopee'),
                    new MenuItem('Product Groups', 'ext.shopee.product-groups.index', 'manage_shopee'),
                    new MenuItem('Orders', 'ext.shopee.orders.index', 'manage_shopee_orders'),
                ],
            ),
        ];
    }

    public function orderTabs(): array
    {
        return [
            new OrderTab(
                id: $this->id,
                label: 'Shopee',
                icon: 'shopee',
                accent: '#ee4d2d',
                routeName: 'ext.shopee.orders.index',
                permission: 'manage_shopee_orders',
                unprocessedCounter: function () {
                    if (!Schema::hasTable('shopee_orders')) {
                        return 0;
                    }
                    $region = 'ph';
                    if (Schema::hasTable('shopee_settings')) {
                        $region = ShopeeSetting::query()->value('region') ?? 'ph';
                    }
                    return ShopeeOrder::query()
                        ->where('region', $region)
                        ->whereIn('status', ['READY_TO_SHIP'])
                        ->count();
                },
                dailyOrdersCounter: function () {
                    if (!Schema::hasTable('shopee_orders')) {
                        return 0;
                    }
                    return ShopeeOrder::query()
                        ->where('order_created_at', '>=', now()->startOfDay())
                        ->count();
                },
                dailyRevenueCounter: function () {
                    if (!Schema::hasTable('shopee_orders') || !Schema::hasTable('shopee_order_products')) {
                        return 0.0;
                    }
                    return (float) DB::table('shopee_order_products as p')
                        ->join('shopee_orders as o', 'o.id', '=', 'p.shopee_order_id')
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->sum(DB::raw('p.quantity * p.price'));
                },
                topProductsCallback: function (int $limit) {
                    if (!Schema::hasTable('shopee_orders') || !Schema::hasTable('shopee_order_products')) {
                        return [];
                    }
                    return DB::table('shopee_order_products as p')
                        ->join('shopee_orders as o', 'o.id', '=', 'p.shopee_order_id')
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->whereNotNull('p.sku')
                        ->where('p.sku', '!=', '')
                        ->groupBy('p.sku')
                        ->orderByDesc('qty_sold')
                        ->limit($limit)
                        ->select(
                            'p.sku',
                            DB::raw('MAX(p.name) as name'),
                            DB::raw('MAX(p.image) as image'),
                            DB::raw('SUM(p.quantity) as qty_sold'),
                            DB::raw('SUM(p.quantity * p.price) as revenue'),
                        )
                        ->get()
                        ->map(fn ($r) => new TopProduct(
                            sku: (string) $r->sku,
                            name: (string) ($r->name ?? $r->sku),
                            imageUrl: $r->image ?: null,
                            qtySold: (int) $r->qty_sold,
                            revenue: (float) $r->revenue,
                        ))->all();
                },
                recentOrdersCallback: function (int $limit) {
                    if (!Schema::hasTable('shopee_orders')) {
                        return [];
                    }
                    $orders = ShopeeOrder::query()
                        ->orderByDesc('order_created_at')
                        ->limit($limit)
                        ->get(['id', 'order_sn', 'status', 'order_created_at']);

                    $totals = DB::table('shopee_order_products')
                        ->whereIn('shopee_order_id', $orders->pluck('id'))
                        ->select('shopee_order_id', DB::raw('SUM(quantity * price) as total'))
                        ->groupBy('shopee_order_id')
                        ->pluck('total', 'shopee_order_id');

                    return $orders->map(fn ($o) => new RecentOrder(
                        reference: (string) $o->order_sn,
                        customerName: null,
                        total: (float) ($totals[$o->id] ?? 0),
                        statusLabel: (string) $o->status,
                        orderedAt: $o->order_created_at,
                        url: route('ext.shopee.orders.show', $o->order_sn),
                    ))->all();
                },
            ),
        ];
    }

    /**
     * React to a SKU change on a catalog product by pushing the new
     * item_sku to every linked Shopee item via the Shopee API.
     */
    public function pushSkuChanges(int $productId, array $skuChanges): ?string
    {
        $links = ShopeeProductLink::where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return null;
        }

        $results = [];

        if (!empty($skuChanges['product_sku'])) {
            $newSku = $skuChanges['product_sku']['new'];
            $itemLinks = $links->whereNull('shopee_model_id')->unique('shopee_item_id');

            foreach ($itemLinks as $link) {
                $itemId = (int) $link->shopee_item_id;
                if ($itemId <= 0) {
                    continue;
                }

                $apiResult = $this->shopeeUpdateItemSku($link, $itemId, $newSku);
                if ($apiResult) {
                    $results[] = $apiResult;
                }
            }
        }

        if (!empty($skuChanges['option_skus'])) {
            $results[] = 'option SKU(s) updated locally';
        }

        return empty($results) ? null : 'Shopee: ' . implode('; ', $results);
    }

    private function shopeeUpdateItemSku(ShopeeProductLink $link, int $itemId, string $newSku): ?string
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return null;
        }

        try {
            $client = new ShopeeClient();
            $path = '/api/v2/product/update_item';
            $body = [
                'item_id'  => $itemId,
                'item_sku' => $newSku,
            ];

            $result = $client->shopPost(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                $path,
                [],
                $body
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.product.update_item.sku_sync',
                'method'          => 'POST',
                'api_path'        => $path,
                'auth_required'   => true,
                'request_params'  => $body,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok'              => (bool) ($result['ok'] ?? false),
                'response_body'   => $result['body'] ?? null,
                'user_id'         => auth()->id(),
            ]);

            $isOk = ($result['ok'] ?? false) && (($result['body']['error'] ?? '') === '' || ($result['body']['error'] ?? null) === null);

            $link->update([
                'last_synced_at'          => now(),
                'last_sync_action'        => 'sku_sync',
                'last_sync_ok'            => $isOk,
                'last_sync_error_code'    => $isOk ? null : (string) ($result['body']['error'] ?? ''),
                'last_sync_error_message' => $isOk ? null : (string) ($result['body']['message'] ?? ''),
            ]);

            return "Item {$itemId}: " . ($isOk ? 'OK' : 'Failed');
        } catch (\Throwable $e) {
            Log::warning('Shopee item_sku update failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return "Item {$itemId}: Error";
        }
    }

    /**
     * Shopee widgets for the core dashboard: pending order count and
     * sync-status row pulled from the settings table.
     */
    public function dashboardData(): array
    {
        $data = [
            'shopeePending' => 0,
            'shopeeSyncStatus' => null,
        ];

        if (Schema::hasTable('shopee_orders')) {
            $data['shopeePending'] = (int) DB::table('shopee_orders')
                ->whereIn('status', ['READY_TO_SHIP'])
                ->count();
        }

        if (Schema::hasTable('shopee_settings')) {
            $spSetting = ShopeeSetting::query()->first();
            if ($spSetting) {
                $data['shopeeSyncStatus'] = (object) [
                    'order_sync_at' => $spSetting->last_order_sync_at,
                    'push_stock_at' => $spSetting->last_stock_push_at,
                    'return_sync_at' => $spSetting->last_return_sync_at,
                    'expires_at'    => $spSetting->expires_at,
                ];
            }
        }

        return $data;
    }

    /**
     * Shopee fees live in shopee_orders.fees['order_income'] from the
     * Shopee escrow detail API. Returns commission / service / transaction
     * fee / withholding tax as a structured breakdown.
     */
    public function feesForOrder(Order $order): ?array
    {
        if ((string) $order->marketplace_source !== 'shopee') {
            return null;
        }

        $mktOrderId = (string) $order->marketplace_order_id;
        if ($mktOrderId === '' || !Schema::hasTable('shopee_orders')) {
            return null;
        }

        $spOrder = ShopeeOrder::where('order_sn', $mktOrderId)->first();
        if (!$spOrder || empty($spOrder->fees)) {
            return ['total' => 0, 'items' => [], 'source' => 'shopee'];
        }

        $feesRaw = is_array($spOrder->fees) ? $spOrder->fees : [];
        $income = $feesRaw['order_income'] ?? $feesRaw;
        if (!is_array($income)) {
            return ['total' => 0, 'items' => [], 'source' => 'shopee'];
        }

        $items = [];
        $total = 0;

        $commission     = abs((float) ($income['commission_fee'] ?? 0));
        $serviceFee     = abs((float) ($income['service_fee'] ?? 0));
        $txnFee         = abs((float) ($income['seller_transaction_fee'] ?? 0));
        $withholdingTax = abs((float) ($income['withholding_tax'] ?? 0));

        if ($commission > 0)     { $items[] = ['label' => 'Commission',       'amount' => $commission];     $total += $commission; }
        if ($serviceFee > 0)     { $items[] = ['label' => 'Service Fee',      'amount' => $serviceFee];     $total += $serviceFee; }
        if ($txnFee > 0)         { $items[] = ['label' => 'Transaction Fee', 'amount' => $txnFee];          $total += $txnFee; }
        if ($withholdingTax > 0) { $items[] = ['label' => 'Withholding Tax', 'amount' => $withholdingTax]; $total += $withholdingTax; }

        return ['total' => round($total, 2), 'items' => $items, 'source' => 'shopee'];
    }

    // ── Mobile API ─────────────────────────────────────────────

    public function mobilePlatformSlug(): string
    {
        return 'shopee';
    }

    public function mobileIndexResponse(Request $request): array
    {
        $tab = strtoupper((string) $request->query('tab', 'ALL'));
        $search = trim((string) $request->query('q', ''));
        $pendingSub = (string) $request->query('pending_sub', '');
        if ($tab !== 'PENDING' || !array_key_exists($pendingSub, self::MOBILE_PENDING_SUB_MAP)) {
            $pendingSub = '';
        }

        $region = ShopeeSetting::query()->first()->region ?? 'ph';
        $base = ShopeeOrder::query()->where('region', $region);
        $query = ShopeeOrder::query()->where('region', $region);

        if ($tab !== 'ALL') {
            $statuses = $tab === 'PENDING' && $pendingSub !== ''
                ? self::MOBILE_PENDING_SUB_MAP[$pendingSub]
                : (self::MOBILE_TAB_STATUS_MAP[$tab] ?? []);
            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('order_sn', 'like', $like)
                    ->orWhere('raw->buyer_username', 'like', $like)
                    ->orWhere('raw->buyer_user_name', 'like', $like);
            });
        }

        $tabCounts = ['ALL' => (clone $base)->count()];
        foreach (self::MOBILE_TAB_STATUS_MAP as $k => $statuses) {
            $tabCounts[$k] = !empty($statuses) ? (clone $base)->whereIn('status', $statuses)->count() : 0;
        }
        $pendingSubCounts = [];
        foreach (self::MOBILE_PENDING_SUB_MAP as $subKey => $subStatuses) {
            $pendingSubCounts[$subKey] = !empty($subStatuses) ? (clone $base)->whereIn('status', $subStatuses)->count() : 0;
        }

        $orders = $query->orderByDesc('order_created_at')->orderByDesc('created_at')->paginate(20);

        $data = $orders->map(function ($order) {
            $raw = $order->raw ?? [];
            $firstProduct = $order->products()->orderBy('id')->first();
            $productRaw = $firstProduct?->raw ?? [];
            $productImage = $firstProduct?->image
                ?? ($productRaw['image_info']['image_url'] ?? null)
                ?? ($productRaw['product_main_image'] ?? null);

            return [
                'id'              => $order->id,
                'order_id'        => (string) $order->order_sn,
                'status'          => $order->status,
                'buyer_name'      => $raw['buyer_username'] ?? $raw['buyer_user_name'] ?? '',
                'total_amount'    => isset($raw['total_amount']) ? (float) $raw['total_amount'] : null,
                'currency'        => $raw['currency'] ?? '₱',
                'items_count'     => $order->products()->count(),
                'product_image'   => $productImage ?: null,
                'payment_method'  => $raw['payment_method'] ?? null,
                'tracking_number' => $raw['tracking_number'] ?? null,
                'date'            => $order->order_created_at?->format('Y-m-d H:i'),
            ];
        });

        return [
            'data'               => $data,
            'current_page'       => $orders->currentPage(),
            'last_page'          => $orders->lastPage(),
            'total'              => $orders->total(),
            'tab_counts'         => $tabCounts,
            'pending_sub_counts' => $pendingSubCounts,
        ];
    }

    public function mobileShowResponse(int $id): ?array
    {
        $order = ShopeeOrder::with('products')->find($id);
        if (!$order) {
            return null;
        }

        $raw = $order->raw ?? [];
        $addr = $raw['recipient_address'] ?? $raw['shipping_address'] ?? [];

        $products = $order->products->map(function ($p) {
            $pRaw = $p->raw ?? [];
            return [
                'name'      => $p->name,
                'sku'       => $p->sku ?? '',
                'variation' => $p->variation ?? '',
                'quantity'  => $p->quantity,
                'price'     => $p->price ?? 0,
                'image'     => $p->image ?? ($pRaw['image_info']['image_url'] ?? ''),
            ];
        });

        return [
            'order_id'          => $order->order_sn,
            'status'            => $order->status,
            'buyer_name'        => $raw['buyer_username'] ?? $raw['buyer_user_name'] ?? '',
            'total_amount'      => isset($raw['total_amount']) ? (float) $raw['total_amount'] : null,
            'currency'          => $raw['currency'] ?? '₱',
            'payment_method'    => $raw['payment_method'] ?? '',
            'shipping_provider' => $raw['shipping_carrier'] ?? $raw['checkout_shipping_carrier'] ?? '',
            'tracking_number'   => $raw['tracking_no'] ?? $raw['tracking_number'] ?? '',
            'shipping_name'     => $addr['name'] ?? '',
            'shipping_phone'    => $addr['phone'] ?? '',
            'shipping_address'  => $addr['full_address']
                ?? trim(implode(', ', array_filter([
                    $addr['address'] ?? '',
                    $addr['city'] ?? '',
                    $addr['state'] ?? '',
                    $addr['zipcode'] ?? '',
                ]))),
            'date'              => $order->order_created_at?->format('Y-m-d H:i'),
            'products'          => $products->values(),
        ];
    }

    // ── Layout banner + source label ───────────────────────────

    public function layoutBanners(): array
    {
        $banners = [];

        if (Schema::hasTable('shopee_settings')) {
            $setting = ShopeeSetting::query()->first();
            if ($setting && $setting->expires_at && strtotime($setting->expires_at) <= time()) {
                $banners[] = [
                    'label'    => 'Shopee: Access token expired. Auto-refresh may have failed.',
                    'severity' => 'error',
                    'href'     => route('ext.shopee.index'),
                ];
            }
        }

        $paused = Cache::get('shopee_sync_paused');
        if ($paused) {
            $banners[] = [
                'label'    => 'Shopee: Order sync paused — ' . $paused,
                'severity' => 'error',
                'href'     => route('ext.shopee.index'),
            ];
        }

        return $banners;
    }

    public function resolveSourceLabel(string $source): ?string
    {
        return $source === 'shopee' ? 'Shopee' : null;
    }

    public function availableSourceOptions(): array
    {
        return [[
            'value'       => 'shopee',
            'label'       => 'Shopee',
            'badge_class' => 'badge-orange',
            'chart_color' => '#ee4d2d',
        ]];
    }

    /**
     * Claim the bare "/" URL when the request carries Shopee's OAuth
     * callback params. Shopee's authorization flow requires a domain-
     * level redirect URI (no path), so the bare APP_URL is registered
     * as the callback in the Shopee developer console. When neither
     * `code` nor `shop_id` is present, defer so core's HomeController
     * can fall through to /dashboard.
     */
    public function handleRootRoute(Request $request): mixed
    {
        if (!$request->query('code') && !$request->query('shop_id')) {
            return null;
        }

        return app(\Extensions\shopee\Controllers\ShopeeController::class)
            ->root($request, app(ShopeeClient::class));
    }
}
