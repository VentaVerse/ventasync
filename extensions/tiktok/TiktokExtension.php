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

use App\Integrations\Contracts\CredentialRevealer;
use App\Integrations\Concerns\RevealsCredentials;

class TiktokExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor, DashboardContributor, OrderFeesContributor, MobileMarketplaceProvider, LayoutBannerContributor, MarketplaceSourceOptionsProvider, CredentialRevealer
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
                tagline: 'TikTok Shop integration. OAuth, product sync, orders.',
                icon: 'video',
                // Black, not the pink. TikTok's mark is black-first and the
                // legacy build used #1e1e1e here; the pink also measured
                // deltaE 19 from this world's danger red, so a filled TikTok
                // button read as a destructive one.
                accent: '#1e1e1e',
                // Card + menu use the VIEW tier so read-only operators still see
                // TikTok in the Integrations hub and contextual sidebar. The
                // 'manage implies view' cascade in PermissionHierarchy means
                // managers continue to see these entries.
                // The dashboard's own derived key (tiktok/dashboard), not the
                // legacy view_tiktok: the card decides who sees this channel
                // in the hub and the nav, and that must be the same answer
                // the dashboard's door gives, or the menu offers a 404.
                permission: 'view_tiktok/dashboard',
                menu: [
                    new MenuItem('Orders', 'ext.tiktok.orders.index', 'view_tiktok/order', group: 'Sell'),
                    new MenuItem('Returns', 'ext.tiktok.orders.returns', 'view_tiktok/order', group: 'Sell'),
                    new MenuItem('Coupons', 'ext.tiktok.coupons.index', 'view_tiktok/coupon', group: 'Sell'),
                    new MenuItem('Listings', 'ext.tiktok.products.index', 'view_tiktok/product', group: 'Catalog'),
                    new MenuItem('Product Groups', 'ext.tiktok.product-groups.index', 'view_tiktok/product_group', group: 'Catalog'),
                    new MenuItem('Categories', 'ext.tiktok.categories.index', 'view_tiktok/product_group', group: 'Catalog'),
                    new MenuItem('Settings', 'ext.tiktok.index', 'view_tiktok/settings', group: 'Channel'),
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
                accent: '#1e1e1e',
                routeName: 'ext.tiktok.orders.index',
                // OrderTab visibility uses view tier so a TikTok Orders Viewer
                // still sees the marketplace's orders tab on the unified Orders
                // workspace.
                permission: 'view_tiktok/order',
                unprocessedCounter: function () {
                    if (!Schema::hasTable('tiktok_orders')) {
                        return 0;
                    }
                    /* THE SAME STATUSES THE "To Ship" TAB GROUPS.
                     *
                     * This counted only the first sub-step, so the head
                     * sentence and the tab strip 40px below it disagreed on
                     * every channel: "Nothing waiting" over "To Ship 1",
                     * because the one order sat at the second step. An order
                     * awaiting collection is still an order the seller is
                     * holding - it is waiting on them, which is the only
                     * question this figure exists to answer.
                     *
                     * Kept identical to the tab map in the orders panel. If
                     * one moves the other has to, and FoundationTest asserts
                     * they still agree. */
                    return TikTokOrder::query()
                        ->whereIn('status', ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'])
                        ->count();
                },
                /* The steps while the seller holds the parcel, each counted alone:
                 * the fulfilment card leads with To Pack and prints To Handover beside
                 * it, which the staff count against the shelf. The statuses are the
                 * orders panel's own sub-tab map, so the card and the tab agree. */
                stages: [
                    ['key' => 'to_pack', 'label' => 'to pack', 'counter' => fn () => Schema::hasTable('tiktok_orders') ? TikTokOrder::query()->whereIn('status', ['AWAITING_SHIPMENT'])->count() : 0],
                    ['key' => 'to_handover', 'label' => 'to handover', 'counter' => fn () => Schema::hasTable('tiktok_orders') ? TikTokOrder::query()->whereIn('status', ['AWAITING_COLLECTION'])->count() : 0],
                ],
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
                'currency'        => $raw['payment']['currency'] ?? \App\Support\Money::defaultSymbol(),
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
            'currency'          => $payment['currency'] ?? \App\Support\Money::defaultSymbol(),
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
        /* Credential state is NOT answered here any more. It read
           settings.expires_at directly - the LIVE column - while these
           installs select their credential by `mode`, so a sandbox channel
           with a healthy token was announced as expired from a leftover live
           date. partials/integration-banners.blade.php asks ChannelWorkspace
           instead, which is the same object the card, the head and the
           channel sidebar read, so the four cannot disagree. This method
           keeps operational notices only. */
        return [];
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

    use RevealsCredentials;

    /**
     * Readable back, and nothing else is.
     *
     * Public identifiers are deliberately absent: app keys, partner ids and
     * shop ids are not secrets, so they are printed in the settings page and
     * there is nothing to reveal. Only what is genuinely hidden appears here.
     */
    public function revealableCredentials(): array
    {
        return [
            'app_secret' => 'App secret',
            'access_token' => 'Access token',
            'refresh_token' => 'Refresh token',
            'sandbox_app_secret' => 'Sandbox app secret',
            'sandbox_access_token' => 'Sandbox access token',
            'sandbox_refresh_token' => 'Sandbox refresh token',
        ];
    }

    protected function credentialSettingsModel(): string
    {
        return \Extensions\tiktok\Models\TikTokSetting::class;
    }

    public function credentialManagePermission(): string
    {
        return 'manage_tiktok/settings';
    }
}
