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

use App\Integrations\Contracts\CredentialRevealer;
use App\Integrations\Contracts\ListingStateSource;
use App\Integrations\Concerns\RevealsCredentials;

class ShopeeExtension extends ExtensionProvider implements ListingStateSource, IntegrationProvider, SkuSyncContributor, DashboardContributor, OrderFeesContributor, MobileMarketplaceProvider, LayoutBannerContributor, MarketplaceSourceOptionsProvider, RootRouteHandler, CredentialRevealer
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
                \Extensions\shopee\Commands\ShopeeRefreshListingStatus::class,
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
                tagline: 'Shopee marketplace integration. OAuth, products, orders, returns.',
                icon: 'shopee',
                accent: '#ee4d2d',
                // Card + menu use the VIEW tier so read-only operators still
                // see Shopee in the Integrations hub and contextual sidebar.
                // User::hasPermission('view_shopee/dashboard') is satisfied by the
                // view or the manage tier (manage implies view), so managers
                // are not excluded by tightening this gate.
                // The dashboard's own derived key (shopee/dashboard), not the
                // legacy view_shopee: the card decides who sees this channel
                // in the hub and the nav, and that must be the same answer
                // the dashboard's door gives, or the menu offers a 404.
                permission: 'view_shopee/dashboard',
                menu: [
                    new MenuItem('Orders', 'ext.shopee.orders.index', 'view_shopee/order', group: 'Sell'),
                    new MenuItem('Returns', 'ext.shopee.orders.returns', 'view_shopee/order', group: 'Sell'),
                    new MenuItem('Vouchers', 'ext.shopee.vouchers.index', 'view_shopee/voucher', group: 'Sell'),
                    new MenuItem('Listings', 'ext.shopee.products.index', 'view_shopee/product', group: 'Catalog'),
                    new MenuItem('Product Groups', 'ext.shopee.product-groups.index', 'view_shopee/product_group', group: 'Catalog'),
                    new MenuItem('Categories', 'ext.shopee.categories.index', 'view_shopee/category', group: 'Catalog'),
                    new MenuItem('Logistics', 'ext.shopee.logistics.index', 'view_shopee/logistics', group: 'Channel'),
                    new MenuItem('Settings', 'ext.shopee.index', 'view_shopee/settings', group: 'Channel'),
                ],
            ),
        ];
    }

    public function orderTabs(): array
    {
        $shopeeStatusCount = function (array $statuses): int {
            if (!Schema::hasTable('shopee_orders')) {
                return 0;
            }
            $region = Schema::hasTable('shopee_settings') ? (ShopeeSetting::query()->value('region') ?? 'ph') : 'ph';

            return ShopeeOrder::query()->where('region', $region)->whereIn('status', $statuses)->count();
        };

        return [
            new OrderTab(
                id: $this->id,
                label: 'Shopee',
                icon: 'shopee',
                accent: '#ee4d2d',
                routeName: 'ext.shopee.orders.index',
                // OrderTab visibility uses view tier so a Shopee Orders Viewer
                // still sees the marketplace's orders tab on the unified Orders
                // workspace. The destination route GETs are read-tier gated,
                // and per-action write buttons are gated separately in the view.
                permission: 'view_shopee/order',
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
                        ->whereIn('status', ['READY_TO_SHIP', 'PROCESSED'])
                        ->count();
                },
                /* The steps while the seller holds the parcel, each counted alone:
                 * the fulfilment card leads with To Pack and prints To Handover beside
                 * it, which the staff count against the shelf. The statuses are the
                 * orders panel's own sub-tab map, so the card and the tab agree. */
                stages: [
                    ['key' => 'to_pack', 'label' => 'to pack', 'counter' => fn () => $shopeeStatusCount(['READY_TO_SHIP'])],
                    ['key' => 'to_handover', 'label' => 'to handover', 'counter' => fn () => $shopeeStatusCount(['PROCESSED'])],
                ],
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
                        ->orderByDesc('qty_sold')->orderByDesc('revenue')->orderBy('name')
                        ->limit($limit)
                        ->select(
                            'p.sku',
                            DB::raw('MAX(p.name) as name'),
                            DB::raw('MAX(p.image) as image'),
                            DB::raw('SUM(p.quantity) as qty_sold'),
                            DB::raw('MAX(p.variation) as variation'),
                            DB::raw('SUM(p.quantity * p.price) as revenue'),
                        )
                        ->get()
                        ->map(fn ($r) => new TopProduct(
                            sku: (string) $r->sku,
                            name: (string) ($r->name ?? $r->sku),
                            imageUrl: $r->image ?: null,
                            qtySold: (int) $r->qty_sold,
                            revenue: (float) $r->revenue,
                            variation: trim((string) ($r->variation ?? '')) ?: null,
                        ))->all();
                },
                recentOrdersCallback: function (int $limit) {
                    if (!Schema::hasTable('shopee_orders')) {
                        return [];
                    }
                    // `raw` comes along because the buyer's name lives in it
                    // and nowhere else on this table. Without it every Shopee
                    // row on the fulfilment feed printed the "Guest" fallback,
                    // which is not true of a marketplace order: there is a
                    // buyer, the name was simply never read.
                    $orders = ShopeeOrder::query()
                        ->orderByDesc('order_created_at')
                        ->limit($limit)
                        ->get(['id', 'order_sn', 'status', 'order_created_at', 'raw']);

                    $totals = DB::table('shopee_order_products')
                        ->whereIn('shopee_order_id', $orders->pluck('id'))
                        ->select('shopee_order_id', DB::raw('SUM(quantity * price) as total'))
                        ->groupBy('shopee_order_id')
                        ->pluck('total', 'shopee_order_id');

                    return $orders->map(fn ($o) => new RecentOrder(
                        reference: (string) $o->order_sn,
                        customerName: (function () use ($o) {
                            $raw = is_array($o->raw) ? $o->raw : [];
                            // Shopee has shipped both spellings of this field
                            // over the versions of the order API this
                            // extension talks to; the orders table's own
                            // buyer filter searches both for the same reason.
                            foreach (['buyer_username', 'buyer_user_name'] as $key) {
                                $name = trim((string) ($raw[$key] ?? ''));
                                if ($name !== '') {
                                    return $name;
                                }
                            }

                            return null;
                        })(),
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
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
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
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
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
                /* The listing troubles ride the chip (Phase B, #184): a
                 * violation or a vanished listing used to live only on a
                 * page nobody opens daily. Counted by aggregate, and a
                 * failure here must not take the dashboard down. */
                $flags = null;
                $attention = 0;
                try {
                    $sum = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->summary();
                    $attention = (int) $sum['attention'];
                    $parts = [];
                    if ($sum['attention'] > 0) {
                        $parts[] = $sum['attention'] . ' listing' . ($sum['attention'] === 1 ? ' needs' : 's need') . ' attention';
                    }
                    if ($sum['drift'] > 0) {
                        $parts[] = $sum['drift'] . ' behind the catalogue';
                    }
                    $flags = $parts ? implode(' · ', $parts) : null;
                } catch (\Throwable) {
                    // the chip simply carries no flags line
                }

                $data['shopeeSyncStatus'] = (object) [
                    'order_sync_at' => $spSetting->last_order_sync_at,
                    'push_stock_at' => $spSetting->last_stock_push_at,
                    'return_sync_at' => $spSetting->last_return_sync_at,
                    'expires_at'    => $spSetting->expires_at,
                    'listing_flags' => $flags,
                    'listing_attention' => $attention,
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
                'currency'        => $raw['currency'] ?? \App\Support\Money::defaultSymbol(),
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
            'currency'          => $raw['currency'] ?? \App\Support\Money::defaultSymbol(),
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

        /* Credential state is NOT answered here any more. It read
           settings.expires_at directly - the LIVE column - while these
           installs select their credential by `mode`, so a sandbox channel
           with a healthy token was announced as expired from a leftover live
           date. partials/integration-banners.blade.php asks ChannelWorkspace
           instead, which is the same object the card, the head and the
           channel sidebar read, so the four cannot disagree. This method
           keeps operational notices only. */
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
            'partner_key' => 'Partner key',
            'access_token' => 'Access token',
            'refresh_token' => 'Refresh token',
            'sandbox_partner_key' => 'Sandbox partner key',
            'sandbox_access_token' => 'Sandbox access token',
            'sandbox_refresh_token' => 'Sandbox refresh token',
            'push_partner_key' => 'Push partner key',
        ];
    }

    protected function credentialSettingsModel(): string
    {
        return \Extensions\shopee\Models\ShopeeSetting::class;
    }

    public function credentialManagePermission(): string
    {
        return 'manage_shopee/settings';
    }

    /**
     * Phase B (#184): the one answer to "where does this product stand on
     * Shopee?", for core surfaces that render listing state.
     */
    public function listingStates(array $productIds): array
    {
        return app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->forProducts($productIds);
    }

    public function listingUrls(array $productIds): array
    {
        $out = [];
        foreach ($productIds as $pid) {
            $out[(int) $pid] = route('ext.shopee.listings.edit', (int) $pid);
        }

        return $out;
    }
}
