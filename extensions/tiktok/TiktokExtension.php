<?php

namespace Extensions\tiktok;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\LayoutBannerContributor;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
use App\Integrations\Contracts\MobileMarketplaceProvider;
use App\Integrations\Contracts\OrderFeesContributor;
use App\Integrations\Contracts\SkuSyncContributor;
use App\Models\Catalog\Order;
use Illuminate\Http\Request;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TiktokExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor, DashboardContributor, OrderFeesContributor, MobileMarketplaceProvider, LayoutBannerContributor, MarketplaceSourceOptionsProvider
{
    private const MOBILE_TAB_STATUS_MAP = [
        'UNPAID'              => ['UNPAID'],
        'PENDING'             => ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'],
        'SHIPPING'            => ['IN_TRANSIT'],
        'DELIVERED_COMPLETED' => ['DELIVERED', 'COMPLETED'],
        'CANCELLED'           => ['CANCELLED'],
    ];

    private const MOBILE_PENDING_SUB_MAP = [
        'to_pack'     => ['AWAITING_SHIPMENT'],
        'to_handover' => ['AWAITING_COLLECTION'],
    ];

    protected string $id = 'tiktok';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\tiktok\Commands\TikTokPushStock::class,
                \Extensions\tiktok\Commands\TikTokRefreshToken::class,
                \Extensions\tiktok\Commands\TikTokSyncOrders::class,
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
                name: 'TikTok Shop',
                tagline: 'TikTok Shop integration — OAuth, product sync, orders.',
                icon: 'video',
                accent: '#fe2c55',
                permission: 'manage_tiktok',
                menu: [
                    new MenuItem('Settings', 'ext.tiktok.index', 'manage_tiktok'),
                    new MenuItem('Product Groups', 'ext.tiktok.product-groups.index', 'manage_tiktok'),
                    new MenuItem('Orders', 'ext.tiktok.orders.index', 'manage_tiktok_orders'),
                ],
            ),
        ];
    }

    public function orderTabs(): array
    {
        return [
            new OrderTab(
                id: $this->id,
                label: 'TikTok',
                icon: 'video',
                accent: '#fe2c55',
                routeName: 'ext.tiktok.orders.index',
                permission: 'manage_tiktok_orders',
                unprocessedCounter: function () {
                    if (!Schema::hasTable('tiktok_orders')) {
                        return 0;
                    }
                    return TikTokOrder::query()->where('status', 'AWAITING_SHIPMENT')->count();
                },
                dailyOrdersCounter: function () {
                    if (!Schema::hasTable('tiktok_orders')) {
                        return 0;
                    }
                    return TikTokOrder::query()
                        ->where('order_created_at', '>=', now()->startOfDay())
                        ->count();
                },
                dailyRevenueCounter: function () {
                    if (!Schema::hasTable('tiktok_orders') || !Schema::hasTable('tiktok_order_products')) {
                        return 0.0;
                    }
                    return (float) DB::table('tiktok_order_products as p')
                        ->join('tiktok_orders as o', 'o.id', '=', 'p.tiktok_order_id')
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->sum(DB::raw('p.quantity * COALESCE(p.sale_price, p.item_price)'));
                },
                topProductsCallback: function (int $limit) {
                    if (!Schema::hasTable('tiktok_orders') || !Schema::hasTable('tiktok_order_products')) {
                        return [];
                    }
                    return DB::table('tiktok_order_products as p')
                        ->join('tiktok_orders as o', 'o.id', '=', 'p.tiktok_order_id')
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
                            DB::raw('SUM(p.quantity * COALESCE(p.sale_price, p.item_price)) as revenue'),
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
                    if (!Schema::hasTable('tiktok_orders')) {
                        return [];
                    }
                    $orders = TikTokOrder::query()
                        ->orderByDesc('order_created_at')
                        ->limit($limit)
                        ->get(['id', 'order_id', 'status', 'buyer_name', 'order_created_at']);

                    $totals = DB::table('tiktok_order_products')
                        ->whereIn('tiktok_order_id', $orders->pluck('id'))
                        ->select('tiktok_order_id', DB::raw('SUM(quantity * COALESCE(sale_price, item_price)) as total'))
                        ->groupBy('tiktok_order_id')
                        ->pluck('total', 'tiktok_order_id');

                    return $orders->map(fn ($o) => new RecentOrder(
                        reference: (string) $o->order_id,
                        customerName: $o->buyer_name,
                        total: (float) ($totals[$o->id] ?? 0),
                        statusLabel: (string) $o->status,
                        orderedAt: $o->order_created_at,
                        url: route('ext.tiktok.orders.show', $o->id),
                    ))->all();
                },
            ),
        ];
    }

    /**
     * React to a SKU change on a catalog product by pushing the new
     * seller_sku to every linked TikTok shop product. Uses the partial-
     * edit endpoint via TikTokClient::editProduct — only the changed
     * `skus[]` payload is sent, the rest of the product stays untouched.
     */
    public function pushSkuChanges(int $productId, array $skuChanges): ?string
    {
        if (empty($skuChanges['product_sku']) || !Schema::hasTable('tiktok_product_group_products')) {
            return null;
        }

        $newSku = $skuChanges['product_sku']['new'];

        $links = TikTokProductGroupProduct::where('product_id', $productId)
            ->whereNotNull('tiktok_product_id')
            ->whereNotNull('tiktok_sku_id')
            ->get();

        if ($links->isEmpty()) {
            return null;
        }

        $setting = TikTokSetting::query()->first();
        if (!$setting || !$setting->access_token || !$setting->app_key) {
            return null;
        }

        $client = new TikTokClient();
        $appKey = (string) $setting->app_key;
        $appSecret = (string) $setting->app_secret;
        $token = (string) $setting->access_token;
        $shopCipher = (string) ($setting->shop_cipher ?? '') ?: null;

        $results = [];
        foreach ($links->groupBy('tiktok_product_id') as $tiktokProductId => $linksForProduct) {
            $skusPayload = $linksForProduct->map(fn ($l) => [
                'id' => (string) $l->tiktok_sku_id,
                'seller_sku' => $newSku,
            ])->values()->all();

            try {
                $result = $client->editProduct($appKey, $appSecret, $token, (string) $tiktokProductId, [
                    'skus' => $skusPayload,
                ], $shopCipher);

                TikTokApiLog::safeCreate([
                    'pack'            => 'tiktok.product.edit.sku_sync',
                    'method'          => 'PUT',
                    'api_path'        => '/product/202309/products/' . $tiktokProductId,
                    'auth_required'   => true,
                    'request_params'  => ['skus' => $skusPayload],
                    'response_status' => (int) ($result['status'] ?? 0),
                    'ok'              => (bool) ($result['ok'] ?? false),
                    'response_body'   => $result['body'] ?? [],
                    'user_id'         => auth()->id(),
                ]);

                $apiCode = (int) ($result['body']['code'] ?? -1);
                $apiOk = ($result['ok'] ?? false) && $apiCode === 0;
                $results[] = "Product {$tiktokProductId}: " . ($apiOk ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('TikTok SKU edit failed', [
                    'tiktok_product_id' => $tiktokProductId,
                    'error' => $e->getMessage(),
                ]);
                $results[] = "Product {$tiktokProductId}: Error";
            }
        }

        return empty($results) ? null : 'TikTok: ' . implode(', ', $results);
    }

    /**
     * TikTok widgets for the core dashboard: pending order count and
     * sync-status row pulled from the settings table.
     */
    public function dashboardData(): array
    {
        $data = [
            'tiktokPending' => 0,
            'tiktokSyncStatus' => null,
        ];

        if (Schema::hasTable('tiktok_settings')) {
            $ttSetting = TikTokSetting::query()->first();
            if ($ttSetting) {
                $data['tiktokSyncStatus'] = (object) [
                    'order_sync_at'  => $ttSetting->last_order_sync_at,
                    'push_stock_at'  => $ttSetting->last_stock_push_at,
                    'expires_at'     => $ttSetting->expires_at,
                ];
            }
        }

        if (Schema::hasTable('tiktok_orders')) {
            $data['tiktokPending'] = (int) DB::table('tiktok_orders')
                ->where('status', 'AWAITING_SHIPMENT')
                ->count();
        }

        return $data;
    }

    /**
     * TikTok fees come from tiktok_orders.fees (commission, transaction fee,
     * shipping fee).
     */
    public function feesForOrder(Order $order): ?array
    {
        if ((string) $order->marketplace_source !== 'tiktok') {
            return null;
        }

        $mktOrderId = (string) $order->marketplace_order_id;
        if ($mktOrderId === '' || !Schema::hasTable('tiktok_orders')) {
            return null;
        }

        $ttOrder = TikTokOrder::where('order_id', $mktOrderId)->first();
        if (!$ttOrder || empty($ttOrder->fees)) {
            return ['total' => 0, 'items' => [], 'source' => 'tiktok'];
        }

        $fees = is_array($ttOrder->fees) ? $ttOrder->fees : [];
        $items = [];
        $total = 0;

        $commission  = abs((float) ($fees['commission'] ?? 0));
        $txnFee      = abs((float) ($fees['transaction_fee'] ?? 0));
        $shippingFee = abs((float) ($fees['shipping_fee'] ?? 0));

        if ($commission > 0)  { $items[] = ['label' => 'Commission',       'amount' => $commission];  $total += $commission; }
        if ($txnFee > 0)      { $items[] = ['label' => 'Transaction Fee', 'amount' => $txnFee];      $total += $txnFee; }
        if ($shippingFee > 0) { $items[] = ['label' => 'Shipping Fee',    'amount' => $shippingFee]; $total += $shippingFee; }

        return ['total' => round($total, 2), 'items' => $items, 'source' => 'tiktok'];
    }

    // ── Mobile API ─────────────────────────────────────────────

    public function mobilePlatformSlug(): string
    {
        return 'tiktok';
    }

    public function mobileIndexResponse(Request $request): array
    {
        $tab = strtoupper((string) $request->query('tab', 'ALL'));
        $search = trim((string) $request->query('q', ''));
        $pendingSub = (string) $request->query('pending_sub', '');
        if ($tab !== 'PENDING' || !array_key_exists($pendingSub, self::MOBILE_PENDING_SUB_MAP)) {
            $pendingSub = '';
        }

        $base = TikTokOrder::query();
        $query = TikTokOrder::query();

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
                $q->where('order_id', 'like', $like)->orWhere('buyer_name', 'like', $like);
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
                'order_id'        => (string) $order->order_id,
                'status'          => $order->status,
                'buyer_name'      => $order->buyer_name ?? '',
                'total_amount'    => isset($raw['payment']['total_amount']) ? (float) $raw['payment']['total_amount'] : null,
                'currency'        => $raw['payment']['currency'] ?? '₱',
                'items_count'     => $order->products()->count(),
                'product_image'   => $productImage ?: null,
                'payment_method'  => $raw['payment']['payment_method_name'] ?? null,
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
        $order = TikTokOrder::with('products')->find($id);
        if (!$order) {
            return null;
        }

        $raw = $order->raw ?? [];
        $payment = $raw['payment'] ?? [];
        $addr = $raw['recipient_address'] ?? [];

        $products = $order->products
            ->groupBy(fn ($p) => ($p->sku ?? '') . '|' . ($p->variation ?? ''))
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'name'      => $first->name,
                    'sku'       => $first->sku ?? '',
                    'variation' => $first->variation ?? '',
                    'quantity'  => $group->sum('quantity'),
                    'price'     => $first->sale_price ?? $first->item_price ?? 0,
                    'image'     => $first->image ?? '',
                ];
            });

        return [
            'order_id'          => (string) $order->order_id,
            'status'            => $order->status,
            'buyer_name'        => $order->buyer_name ?? '',
            'total_amount'      => isset($payment['total_amount']) ? (float) $payment['total_amount'] : null,
            'currency'          => $payment['currency'] ?? '₱',
            'payment_method'    => $payment['payment_method_name'] ?? '',
            'shipping_provider' => $raw['shipping_provider'] ?? $raw['shipping_provider_id'] ?? '',
            'tracking_number'   => $raw['tracking_number'] ?? '',
            'shipping_name'     => $addr['name'] ?? $order->buyer_name ?? '',
            'shipping_phone'    => $addr['phone_number'] ?? $addr['phone'] ?? '',
            'shipping_address'  => $addr['full_address']
                ?? trim(implode(', ', array_filter([
                    $addr['address_detail'] ?? '',
                    $addr['district'] ?? '',
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
        if (!Schema::hasTable('tiktok_settings')) {
            return [];
        }
        $setting = TikTokSetting::query()->first();
        if (!$setting || !$setting->expires_at || strtotime($setting->expires_at) > time()) {
            return [];
        }
        return [[
            'label'    => 'TikTok Shop: Access token expired.',
            'severity' => 'error',
            'href'     => route('ext.tiktok.index'),
        ]];
    }

    public function resolveSourceLabel(string $source): ?string
    {
        return $source === 'tiktok' ? 'TikTok' : null;
    }

    public function availableSourceOptions(): array
    {
        return [[
            'value'       => 'tiktok',
            'label'       => 'TikTok',
            'badge_class' => 'badge-dark',
            'chart_color' => '#1e1e1e',
        ]];
    }
}
