<?php

namespace Extensions\lazada;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\LayoutBannerContributor;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
use App\Integrations\Contracts\MobileMarketplaceProvider;
use App\Integrations\Contracts\OrderFeesContributor;
use App\Integrations\Contracts\OrderImagesContributor;
use App\Integrations\Contracts\SkuResolver;
use App\Integrations\Contracts\SkuSyncContributor;
use Illuminate\Http\Request;
use App\Models\Catalog\Order;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LazadaExtension extends ExtensionProvider implements IntegrationProvider, SkuResolver, SkuSyncContributor, DashboardContributor, OrderImagesContributor, OrderFeesContributor, MobileMarketplaceProvider, LayoutBannerContributor, MarketplaceSourceOptionsProvider
{
    private const MOBILE_TAB_STATUS_MAP = [
        'UNPAID'              => ['unpaid'],
        'PENDING'             => ['pending', 'repacked', 'packed', 'ready_to_ship'],
        'SHIPPING'            => ['shipped'],
        'DELIVERED_COMPLETED' => ['delivered'],
        'CANCELLED'           => ['canceled', 'cancelled'],
        'RETURN'              => ['returned'],
    ];

    private const MOBILE_PENDING_SUB_MAP = [
        'to_pack'     => ['pending', 'repacked'],
        'to_arrange'  => ['packed'],
        'to_handover' => ['ready_to_ship'],
    ];

    protected string $id = 'lazada';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\lazada\Commands\LazadaPushStock::class,
                \Extensions\lazada\Commands\LazadaRefreshToken::class,
                \Extensions\lazada\Commands\LazadaSyncOrders::class,
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
                name: 'Lazada',
                tagline: 'Lazada marketplace integration — products, orders, returns.',
                icon: 'lazada',
                accent: '#0f146d',
                permission: 'manage_lazada',
                menu: [
                    new MenuItem('Settings', 'ext.lazada.index', 'manage_lazada'),
                    new MenuItem('Product Groups', 'ext.lazada.product-groups.index', 'manage_lazada'),
                    new MenuItem('Orders', 'ext.lazada.orders.index', 'manage_lazada_orders', null, ['tab' => 'TO_SHIP']),
                ],
            ),
        ];
    }

    public function orderTabs(): array
    {
        return [
            new OrderTab(
                id: $this->id,
                label: 'Lazada',
                icon: 'lazada',
                accent: '#0f146d',
                routeName: 'ext.lazada.orders.index',
                permission: 'manage_lazada_orders',
                routeParams: ['tab' => 'TO_SHIP'],
                unprocessedCounter: function () {
                    if (!Schema::hasTable('lazada_orders')) {
                        return 0;
                    }
                    $region = 'ph';
                    if (Schema::hasTable('lazada_settings')) {
                        $region = LazadaSetting::query()->value('region') ?? 'ph';
                    }
                    return LazadaOrder::query()
                        ->where('region', $region)
                        ->whereIn('status', ['pending', 'repacked'])
                        ->count();
                },
                dailyOrdersCounter: function () {
                    if (!Schema::hasTable('lazada_orders')) {
                        return 0;
                    }
                    return LazadaOrder::query()
                        ->where('order_created_at', '>=', now()->startOfDay())
                        ->count();
                },
                dailyRevenueCounter: function () {
                    if (!Schema::hasTable('lazada_orders') || !Schema::hasTable('lazada_order_products')) {
                        return 0.0;
                    }
                    return (float) DB::table('lazada_order_products as p')
                        ->join('lazada_orders as o', 'o.id', '=', 'p.lazada_order_id')
                        ->where('o.order_created_at', '>=', now()->startOfDay())
                        ->sum(DB::raw('p.quantity * COALESCE(p.paid_price, p.item_price)'));
                },
                topProductsCallback: function (int $limit) {
                    if (!Schema::hasTable('lazada_orders') || !Schema::hasTable('lazada_order_products')) {
                        return [];
                    }
                    return DB::table('lazada_order_products as p')
                        ->join('lazada_orders as o', 'o.id', '=', 'p.lazada_order_id')
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
                            DB::raw('SUM(p.quantity * COALESCE(p.paid_price, p.item_price)) as revenue'),
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
                    if (!Schema::hasTable('lazada_orders')) {
                        return [];
                    }
                    $orders = LazadaOrder::query()
                        ->orderByDesc('order_created_at')
                        ->limit($limit)
                        ->get(['id', 'order_id', 'status', 'order_created_at']);

                    $totals = DB::table('lazada_order_products')
                        ->whereIn('lazada_order_id', $orders->pluck('id'))
                        ->select('lazada_order_id', DB::raw('SUM(quantity * COALESCE(paid_price, item_price)) as total'))
                        ->groupBy('lazada_order_id')
                        ->pluck('total', 'lazada_order_id');

                    return $orders->map(fn ($o) => new RecentOrder(
                        reference: (string) $o->order_id,
                        customerName: null,
                        total: (float) ($totals[$o->id] ?? 0),
                        statusLabel: (string) $o->status,
                        orderedAt: $o->order_created_at,
                        url: route('ext.lazada.orders.show', $o->order_id),
                    ))->all();
                },
            ),
        ];
    }

    /**
     * Translate a Lazada seller_sku into the catalog product mapping.
     * Used by core's OrderStockService when adjusting stock for orders that
     * reference a Lazada-specific SKU (variant maps to a catalog product +
     * optionally an option-value row).
     */
    public function resolveCatalogProduct(string $sku): ?array
    {
        if ($sku === '' || !Schema::hasTable('lazada_product_variants')) {
            return null;
        }

        $variant = LazadaProductVariant::where('seller_sku', $sku)->first();
        if (!$variant) {
            return null;
        }

        $lazadaProduct = LazadaProduct::where('id', $variant->lazada_product_id)->first();
        if (!$lazadaProduct || !$lazadaProduct->product_id) {
            return null;
        }

        return [
            'product_id' => (int) $lazadaProduct->product_id,
            'product_option_value_id' => $variant->product_option_value_id ?: null,
        ];
    }

    /**
     * React to a SKU change on a catalog product by updating the Lazada
     * variant table and pushing the new seller_sku to the Lazada API.
     */
    public function pushSkuChanges(int $productId, array $skuChanges): ?string
    {
        $listing = LazadaProduct::where('product_id', $productId)->first();
        if (!$listing) {
            return null;
        }

        $results = [];

        if (!empty($skuChanges['product_sku'])) {
            $old = $skuChanges['product_sku']['old'];
            $new = $skuChanges['product_sku']['new'];

            $updated = LazadaProductVariant::where('lazada_product_id', $listing->id)
                ->where('seller_sku', $old)
                ->update(['seller_sku' => $new]);

            if ($updated > 0) {
                $results[] = "seller_sku updated ({$old} -> {$new})";
            }

            if (!empty($listing->lazada_item_id)) {
                $apiResult = $this->lazadaUpdateSellerSku($listing, $old, $new);
                if ($apiResult) {
                    $results[] = $apiResult;
                }
            }
        }

        if (!empty($skuChanges['option_skus'])) {
            foreach ($skuChanges['option_skus'] as $povId => $change) {
                $old = $change['old'];
                $new = $change['new'];

                $variant = LazadaProductVariant::where('lazada_product_id', $listing->id)
                    ->where('seller_sku', $old)
                    ->first();

                if ($variant) {
                    $variant->seller_sku = $new;
                    $variant->save();
                    $results[] = "variant SKU ({$old} -> {$new})";
                }
            }
        }

        try {
            $listing->update([
                'last_synced_at' => now(),
                'last_sync_action' => 'sku_sync',
                'last_sync_ok' => true,
            ]);
        } catch (\Throwable) {
            // Non-critical — listing sync metadata is best-effort
        }

        return empty($results) ? null : 'Lazada: ' . implode('; ', $results);
    }

    private function lazadaUpdateSellerSku(LazadaProduct $listing, string $oldSku, string $newSku): ?string
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->access_token || !$setting->app_key) {
            return null;
        }

        try {
            $client = new LazadaClient();
            $apiPath = '/product/update';

            $itemId = htmlspecialchars((string) $listing->lazada_item_id, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $newSkuEsc = htmlspecialchars($newSku, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $payloadXml = '<Request><Product>'
                . '<ItemId>' . $itemId . '</ItemId>'
                . '<Skus><Sku>'
                . '<SellerSku>' . $newSkuEsc . '</SellerSku>'
                . '</Sku></Skus>'
                . '</Product></Request>';

            $params = [
                'app_key'      => (string) $setting->app_key,
                'sign_method'  => 'sha256',
                'timestamp'    => (string) round(microtime(true) * 1000),
                'access_token' => (string) $setting->access_token,
                'payload'      => $payloadXml,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

            $result = $client->post((string) $setting->region, $apiPath, $params);

            LazadaApiLog::safeCreate([
                'pack'            => 'lazada.product.update.sku_sync',
                'method'          => 'POST',
                'api_path'        => $apiPath,
                'auth_required'   => true,
                'request_params'  => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok'              => (bool) ($result['ok'] ?? false),
                'response_body'   => $result['body'] ?? null,
                'user_id'         => auth()->id(),
            ]);

            $body = $result['body'] ?? [];
            $code = is_array($body) ? ($body['code'] ?? null) : null;

            if (($result['ok'] ?? false) && ($code === '0' || $code === 0 || $code === null)) {
                return 'API OK';
            }

            $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
            Log::info('Lazada SellerSku update response', ['code' => $code, 'message' => $msg]);
            return 'API: ' . $msg;
        } catch (\Throwable $e) {
            Log::warning('Lazada SellerSku update failed', ['error' => $e->getMessage()]);
            return 'API error';
        }
    }

    /**
     * Lazada widgets for the core dashboard: total products, pending orders,
     * and the sync-status row pulled from the settings table.
     */
    public function dashboardData(): array
    {
        $data = [
            'lazadaProducts' => 0,
            'lazadaPending' => 0,
            'lazadaSyncStatus' => null,
        ];

        if (Schema::hasTable('lazada_products')) {
            $data['lazadaProducts'] = (int) DB::table('lazada_products')->count();
        }

        if (Schema::hasTable('lazada_orders')) {
            $data['lazadaPending'] = (int) DB::table('lazada_orders')
                ->whereIn('status', ['pending', 'repacked'])
                ->count();
        }

        if (Schema::hasTable('lazada_settings')) {
            $lzSetting = LazadaSetting::query()->first();
            if ($lzSetting) {
                $data['lazadaSyncStatus'] = (object) [
                    'order_sync_at' => $lzSetting->last_order_sync_at,
                    'push_stock_at' => $lzSetting->last_stock_push_at,
                    'return_sync_at' => $lzSetting->last_return_sync_at,
                    'expires_at'    => $lzSetting->expires_at,
                ];
            }
        }

        return $data;
    }

    /**
     * Lazada catalog orders' product images live in lazada_order_products.
     * Look up the Lazada order by catalog_order_id, then map each product
     * back to its catalog order_product_id via SKU.
     */
    public function imagesForCatalogOrders(array $catalogOrderIds): array
    {
        if (empty($catalogOrderIds) || !Schema::hasTable('lazada_orders')) {
            return [];
        }

        $lazadaOrders = LazadaOrder::whereIn('catalog_order_id', $catalogOrderIds)
            ->with('products')
            ->get()
            ->keyBy('catalog_order_id');

        if ($lazadaOrders->isEmpty()) {
            return [];
        }

        // We need to walk catalog order_products to associate by SKU. The
        // caller (core OrderController) loads orders eager with products,
        // but the contract here only takes catalog_order_ids. Read the
        // catalog order_products table directly.
        $pfx = (string) config('catalog.prefix');
        $opTable = $pfx . 'order_product';
        if (!Schema::hasTable($opTable)) {
            return [];
        }

        $orderProducts = DB::table($opTable)
            ->whereIn('order_id', $catalogOrderIds)
            ->get(['order_product_id', 'order_id', 'model']);

        $result = [];
        foreach ($orderProducts as $op) {
            $lzOrder = $lazadaOrders->get($op->order_id);
            if (!$lzOrder) {
                continue;
            }
            $sku = trim((string) $op->model);
            if ($sku === '') {
                continue;
            }
            $lzProduct = $lzOrder->products->firstWhere('sku', $sku);
            $image = $lzProduct ? trim((string) ($lzProduct->image ?? '')) : '';
            if ($image !== '') {
                $result[(int) $op->order_product_id] = $image;
            }
        }

        return $result;
    }

    /**
     * Lazada fees come from the lazada_orders.fees JSON column. Returns the
     * commission / payment fee / shipping cost / negative other_fees as a
     * structured breakdown.
     */
    public function feesForOrder(Order $order): ?array
    {
        if ((string) $order->marketplace_source !== 'lazada') {
            return null;
        }

        $mktOrderId = (string) $order->marketplace_order_id;
        if ($mktOrderId === '' || !Schema::hasTable('lazada_orders')) {
            return null;
        }

        $lzOrder = LazadaOrder::where('order_id', $mktOrderId)->first();
        if (!$lzOrder || empty($lzOrder->fees)) {
            return ['total' => 0, 'items' => [], 'source' => 'lazada'];
        }

        $fees = is_array($lzOrder->fees) ? $lzOrder->fees : [];
        $items = [];
        $total = 0;

        $commission = abs((float) ($fees['commission'] ?? 0));
        $paymentFee = abs((float) ($fees['payment_fee'] ?? 0));
        $shippingCost = abs((float) ($fees['shipping_service_cost'] ?? 0));

        if ($commission > 0)   { $items[] = ['label' => 'Commission',    'amount' => $commission];  $total += $commission; }
        if ($paymentFee > 0)   { $items[] = ['label' => 'Payment Fee',   'amount' => $paymentFee];  $total += $paymentFee; }
        if ($shippingCost > 0) { $items[] = ['label' => 'Shipping Fee',  'amount' => $shippingCost]; $total += $shippingCost; }

        $otherFees = $fees['other_fees'] ?? [];
        if (is_array($otherFees)) {
            foreach ($otherFees as $label => $amount) {
                if ((float) $amount < 0) {
                    $amt = abs((float) $amount);
                    $items[] = ['label' => ucwords(str_replace('_', ' ', $label)), 'amount' => $amt];
                    $total += $amt;
                }
            }
        }

        return ['total' => round($total, 2), 'items' => $items, 'source' => 'lazada'];
    }

    // ── Mobile API ─────────────────────────────────────────────

    public function mobilePlatformSlug(): string
    {
        return 'lazada';
    }

    public function mobileIndexResponse(Request $request): array
    {
        $tab = strtoupper((string) $request->query('tab', 'ALL'));
        $search = trim((string) $request->query('q', ''));
        $pendingSub = (string) $request->query('pending_sub', '');
        if ($tab !== 'PENDING' || !array_key_exists($pendingSub, self::MOBILE_PENDING_SUB_MAP)) {
            $pendingSub = '';
        }

        $region = LazadaSetting::query()->first()->region ?? 'ph';
        $base = LazadaOrder::query()->where('region', $region);
        $query = LazadaOrder::query()->where('region', $region);

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
                $q->where('order_id', 'like', $like)
                    ->orWhere('raw->customer_first_name', 'like', $like)
                    ->orWhere('raw->customer_name', 'like', $like)
                    ->orWhere('raw->buyer_name', 'like', $like);
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
                'buyer_name'      => $raw['customer_first_name'] ?? $raw['customer_name'] ?? $raw['buyer_name'] ?? '',
                'total_amount'    => isset($raw['price']) ? (float) $raw['price'] : null,
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
        $order = LazadaOrder::with('products')->find($id);
        if (!$order) {
            return null;
        }

        $raw = $order->raw ?? [];
        $detail = $raw['_detail'] ?? $raw;
        $address = $detail['address_shipping'] ?? [];

        $products = $order->products
            ->groupBy(fn ($p) => ($p->sku ?? '') . '|' . ($p->variation ?? ''))
            ->map(function ($group) {
                $first = $group->first();
                $pRaw = $first->raw ?? [];
                return [
                    'name'      => $first->name,
                    'sku'       => $first->sku ?? '',
                    'variation' => $first->variation ?? '',
                    'quantity'  => $group->sum('quantity'),
                    'price'     => isset($pRaw['item_price']) ? (float) $pRaw['item_price'] : 0,
                    'image'     => $first->image ?? $pRaw['product_main_image'] ?? '',
                ];
            });

        return [
            'order_id'          => (string) $order->order_id,
            'status'            => $order->status,
            'buyer_name'        => $detail['customer_first_name'] ?? $detail['customer_name'] ?? $detail['buyer_name'] ?? '',
            'total_amount'      => isset($detail['price']) ? (float) $detail['price'] : null,
            'currency'          => $detail['currency'] ?? '₱',
            'payment_method'    => $detail['payment_method'] ?? '',
            'shipping_provider' => $detail['shipping_provider'] ?? '',
            'tracking_number'   => $detail['tracking_code'] ?? $detail['tracking_number'] ?? '',
            'shipping_name'     => trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')),
            'shipping_phone'    => $address['phone'] ?? $address['phone2'] ?? '',
            'shipping_address'  => trim(implode(', ', array_filter([
                $address['address1'] ?? '',
                $address['address2'] ?? '',
                $address['address3'] ?? '',
                $address['city'] ?? '',
                $address['post_code'] ?? '',
            ]))),
            'date'              => $order->order_created_at?->format('Y-m-d H:i'),
            'products'          => $products->values(),
        ];
    }

    // ── Layout banner + source label ───────────────────────────

    public function layoutBanners(): array
    {
        if (!Schema::hasTable('lazada_settings')) {
            return [];
        }
        $setting = LazadaSetting::query()->first();
        if (!$setting || !$setting->expires_at || strtotime($setting->expires_at) > time()) {
            return [];
        }
        return [[
            'label'    => 'Lazada: Access token expired. Order sync is paused.',
            'severity' => 'error',
            'href'     => route('ext.lazada.index'),
        ]];
    }

    public function resolveSourceLabel(string $source): ?string
    {
        return $source === 'lazada' ? 'Lazada' : null;
    }

    public function availableSourceOptions(): array
    {
        return [[
            'value'       => 'lazada',
            'label'       => 'Lazada',
            'badge_class' => 'badge-indigo',
            'chart_color' => '#0F146D',
        ]];
    }
}
