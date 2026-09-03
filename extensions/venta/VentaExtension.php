<?php

namespace Extensions\venta;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\MarketplaceSourceOptionsProvider;
use App\Integrations\Contracts\ProductActionContributor;
use App\Integrations\Contracts\SkuSyncContributor;
use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Integrations\StoreLink;
use App\Models\Catalog\OrderStatus;
use App\Support\FulfilmentSteps;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Extensions\venta\Services\Venta\VentaStatusPlacements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Venta is a multi-store integration: each enabled VentaSetting is its own
 * fulfillable surface. integrationCards() and orderTabs() return one entry
 * per enabled store, with metrics scoped to that store via the
 * marketplace_source = "venta:{id}" convention used elsewhere in the app.
 */
use App\Integrations\Contracts\CredentialRevealer;
use App\Integrations\Concerns\RevealsCredentials;

class VentaExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor, DashboardContributor, MarketplaceSourceOptionsProvider, CredentialRevealer, ProductActionContributor
{
    protected string $id = 'venta';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\venta\Commands\VentaSync::class,
                \Extensions\venta\Commands\VentaPushStock::class,
                \Extensions\venta\Commands\VentaPushReviews::class,
                \Extensions\venta\Commands\VentaRefreshListingStatus::class,
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
        $stores = [];
        foreach ($this->allStores() as $store) {
            $stores[] = new StoreLink(
                id: $this->id . ':' . $store->id,
                label: $store->store_name,
                menu: [
                    // Phase 4 — view-tier visibility: read-only operators can
                    // open Settings / Product Groups / Orders for browsing.
                    //
                    // The trailing group is the workspace conversion signal:
                    // a store whose menu is grouped gets its own seller
                    // center at /integrations/venta:{id}, built from exactly
                    // this menu, with these ['store' => id] params carried
                    // through unchanged.
                    new MenuItem('Settings', 'ext.venta.settings.show', 'view_venta/settings', null, ['store' => $store->id], 'Channel', children: [
                        ['label' => 'Connection', 'section' => 'connection'],
                        ['label' => 'Status mapping', 'section' => 'status'],
                        ['label' => 'Automations', 'section' => 'automations'],
                        ['label' => 'API log', 'section' => 'logs'],
                        ['label' => 'Sync log', 'section' => 'sync'],
                    ]),
                    new MenuItem('Listings', 'ext.venta.listings.index', 'view_venta/listing', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Presets', 'ext.venta.product-groups.index', 'view_venta/product_group', null, ['store' => $store->id], 'Catalog'),
                    new MenuItem('Orders', 'ext.venta.orders.index', 'view_venta/order', null, ['store' => $store->id], 'Sell'),
                ],
                status: $store->enabled ? 'active' : 'setup_pending',
            );
        }

        return [
            new IntegrationCard(
                id: $this->id,
                name: 'Venta',
                tagline: 'Multi-store ecommerce. Add stores, sync orders, push inventory.',
                icon: 'shop',
                accent: '#7c3aed',
                // Card uses view tier so read-only operators still see Venta in
                // the Integrations hub. Add-store action stays manage-only.
                // The dashboard's own derived key (venta/dashboard), not the
                // legacy view_venta: the card decides who sees this channel
                // in the hub and the nav, and that must be the same answer
                // the dashboard's door gives, or the menu offers a 404.
                permission: 'view_venta/dashboard',
                menu: [],
                stores: $stores,
                addStore: new \App\Integrations\AddStoreAction(
                    route: 'ext.venta.stores.store',
                    permission: 'manage_venta/settings',
                ),
            ),
        ];
    }

    public function orderTabs(): array
    {
        $tabs = [];
        foreach ($this->enabledStores() as $store) {
            $tabs[] = $this->buildOrderTab((int) $store->id, $store->store_name);
        }
        return $tabs;
    }

    /**
     * @return iterable<\Extensions\venta\Models\VentaSetting>
     */
    protected function enabledStores(): iterable
    {
        if (!Schema::hasTable('venta_settings')) {
            return [];
        }
        return VentaSetting::where('enabled', true)->orderBy('id')->get();
    }

    /**
     * All stores regardless of enabled flag — used by the Integrations UI
     * (cards / sidebar / module page) so freshly-created disabled stores
     * remain visible to the operator while they finish configuring them.
     * Sync/cron paths continue to filter via enabledStores().
     *
     * @return iterable<\Extensions\venta\Models\VentaSetting>
     */
    protected function allStores(): iterable
    {
        if (!Schema::hasTable('venta_settings')) {
            return [];
        }
        return VentaSetting::orderBy('id')->get();
    }

    /**
     * How many of a store's orders sit in each fulfilment step, keyed by
     * step (to_pack, to_handover, ...), every step present at zero.
     *
     * One GROUP BY over venta_orders.status, bucketed through the same map
     * the orders panel builds its strip from. Public and static because the
     * card counters above and any later reader (the dashboard, a report)
     * must count the same way the panel does, or the numbers on the card
     * and on the strip drift apart again.
     *
     * @return array<string, int>
     */
    public static function stepCounts(int $storeId): array
    {
        if (!Schema::hasTable('venta_orders')) {
            return array_fill_keys(FulfilmentSteps::placements(), 0);
        }

        $statusCounts = VentaOrder::query()
            ->where('venta_setting_id', $storeId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return array_map(
            fn (array $bucket) => $bucket['count'],
            FulfilmentSteps::bucket('venta.orders', $statusCounts, VentaStatusPlacements::resolver($storeId))
        );
    }

    protected function buildOrderTab(int $storeId, string $storeName): OrderTab
    {
        $marketplaceSource = 'venta:' . $storeId;
        $orderTable = (string) config('catalog.prefix') . 'order';
        $productTable = (string) config('catalog.prefix') . 'order_product';

        return new OrderTab(
            id: $this->id . ':' . $storeId,
            label: $storeName,
            icon: 'shop',
            accent: '#7c3aed',
            routeName: 'ext.venta.orders.index',
            // OrderTab visibility uses view tier so a Venta Orders Viewer
            // still sees the per-store tab on the unified Orders workspace.
            permission: 'view_venta/order',
            routeParams: ['store' => $storeId],
            /* COUNT THE TABLE THE TABS COUNT.
             *
             * This read the ERP catalog order table's order_status_id and
             * matched it against Pending/Processing, while the orders list
             * beside it groups venta_orders.status - two different tables
             * holding two different taxonomies. The head therefore said
             * "Nothing waiting" above a tab reading "Start Pickup 1", and
             * neither number was wrong; they were answers to different
             * questions.
             *
             * Waiting means the seller still has the parcel: the To Pack and
             * To Handover steps, and nothing else. The statuses behind each
             * step are the ONE map the orders panel's strip reads
             * (FulfilmentSteps, surface venta.orders), through the same
             * bucketing over the same GROUP BY, so the card and the tabs
             * cannot disagree. An unplaced status the storefront invents
             * lands in Other, visible on the strip, and is not counted as
             * work nobody has to do. */
            unprocessedCounter: function () use ($storeId) {
                $steps = self::stepCounts($storeId);

                return $steps['to_pack'] + $steps['to_handover'];
            },
            /* The steps while the seller holds the parcel, each counted alone:
             * the fulfilment card leads with To Pack and prints To Handover beside
             * it, which the staff count against the shelf. */
            stages: [
                ['key' => 'to_pack', 'label' => 'to pack', 'counter' => fn () => self::stepCounts($storeId)['to_pack']],
                ['key' => 'to_handover', 'label' => 'to handover', 'counter' => fn () => self::stepCounts($storeId)['to_handover']],
            ],
            dailyOrdersCounter: function () use ($marketplaceSource, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return 0;
                }
                return DB::table($orderTable)
                    ->where('marketplace_source', $marketplaceSource)
                    ->where('date_added', '>=', now()->startOfDay())
                    ->count();
            },
            dailyRevenueCounter: function () use ($marketplaceSource, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return 0.0;
                }
                return (float) DB::table($orderTable)
                    ->where('marketplace_source', $marketplaceSource)
                    ->where('date_added', '>=', now()->startOfDay())
                    ->sum('total');
            },
            topProductsCallback: function (int $limit) use ($marketplaceSource, $orderTable, $productTable) {
                if (!Schema::hasTable($orderTable) || !Schema::hasTable($productTable)) {
                    return [];
                }
                $catalogProductTable = (string) config('catalog.prefix') . 'product';
                return DB::table($productTable . ' as p')
                    ->join($orderTable . ' as o', 'o.order_id', '=', 'p.order_id')
                    ->leftJoin($catalogProductTable . ' as cp', 'cp.product_id', '=', 'p.product_id')
                    ->where('o.marketplace_source', $marketplaceSource)
                    ->where('o.date_added', '>=', now()->startOfDay())
                    ->whereNotNull('p.model')
                    ->where('p.model', '!=', '')
                    /* The chosen options, as the storefront wrote them, make the
                     * variation: "Cable Length: 10 Ft". Grouped WITH the model so a
                     * product sold in two variations is two rows, the way the
                     * marketplaces (whose lines carry the variation's own SKU)
                     * already rank. The Venta and Shopify lines carry the
                     * variant SKU in model too; OpenCart's carry the parent's. */
                    ->leftJoin(DB::raw('(SELECT order_product_id, GROUP_CONCAT(CONCAT(name, \': \', value) ORDER BY order_option_id SEPARATOR \', \') AS variation FROM `' . str_replace('order_product', 'order_option', $productTable) . '` GROUP BY order_product_id) AS oo'), 'oo.order_product_id', '=', 'p.order_product_id')
                    ->groupBy('p.model', 'oo.variation')
                    ->orderByDesc('qty_sold')->orderByDesc('revenue')->orderBy('name')
                    ->limit($limit)
                    ->select(
                        'p.model as sku',
                        DB::raw('MAX(p.name) as name'),
                        DB::raw('MAX(cp.image) as image'),
                        DB::raw('oo.variation as variation'),
                        DB::raw('SUM(p.quantity) as qty_sold'),
                        DB::raw('SUM(p.total) as revenue'),
                    )
                    ->get()
                    ->map(fn ($r) => new TopProduct(
                        sku: (string) $r->sku,
                        name: (string) ($r->name ?? $r->sku),
                        imageUrl: $r->image ? asset('storage/' . ltrim($r->image, '/')) : null,
                        qtySold: (int) $r->qty_sold,
                        revenue: (float) $r->revenue,
                        variation: trim((string) ($r->variation ?? '')) ?: null,
                    ))->all();
            },
            recentOrdersCallback: function (int $limit) use ($marketplaceSource, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return [];
                }
                $statuses = Schema::hasTable('order_status')
                    ? OrderStatus::query()->pluck('name', 'order_status_id')->all()
                    : [];

                return DB::table($orderTable)
                    ->where('marketplace_source', $marketplaceSource)
                    ->orderByDesc('date_added')
                    ->limit($limit)
                    ->select('order_id', 'firstname', 'lastname', 'total', 'order_status_id', 'date_added')
                    ->get()
                    ->map(fn ($o) => new RecentOrder(
                        reference: '#' . $o->order_id,
                        customerName: trim(($o->firstname ?? '') . ' ' . ($o->lastname ?? '')) ?: null,
                        total: (float) $o->total,
                        statusLabel: (string) ($statuses[$o->order_status_id] ?? '—'),
                        orderedAt: $o->date_added ? new \DateTimeImmutable((string) $o->date_added) : null,
                        url: route('orders.show', $o->order_id),
                    ))->all();
            },
        );
    }

    /**
     * React to a SKU change on a catalog product by updating the
     * corresponding product on every linked Venta store. Venta's API uses
     * the existing SKU as the path key — PUT /products/{old_sku} with
     * { sku: new_sku } in the body — so we look up each linked store's
     * VentaProductLink, push the rename, and update the link locally.
     */
    public function pushSkuChanges(int $productId, array $skuChanges): ?string
    {
        if (empty($skuChanges['product_sku']) || !Schema::hasTable('venta_product_links')) {
            return null;
        }

        $links = VentaProductLink::where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return null;
        }

        $old = $skuChanges['product_sku']['old'];
        $new = $skuChanges['product_sku']['new'];
        $results = [];

        $settingsById = VentaSetting::query()->where('enabled', true)->get()->keyBy('id');

        foreach ($links as $link) {
            $setting = $settingsById->get($link->venta_setting_id);
            if (!$setting) {
                continue;
            }

            $linkSku = (string) ($link->sku ?: $old);
            try {
                $client = new VentaClient($setting);
                $result = $client->updateProduct($linkSku, ['sku' => $new]);
                $ok = (bool) ($result['ok'] ?? false);

                if ($ok) {
                    $link->update(['sku' => $new]);
                }

                $results[] = $setting->store_name . ': ' . ($ok ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('SKU sync to Venta failed', [
                    'store' => $setting->store_name,
                    'product_id' => $productId,
                    'sku_old' => $linkSku,
                    'sku_new' => $new,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $setting->store_name . ': Error';
            }
        }

        return empty($results) ? null : 'Venta: ' . implode(', ', $results);
    }

    /**
     * Venta widgets for the core dashboard: store-name lookup table for
     * the "Recent orders" and platform breakdown labels.
     */
    /**
     * The product page's way into this product's listing on each store
     * (#171). One action per enabled store, because each store is a
     * separate live shop with its own record of the product.
     */
    public function productActions(int $productId): array
    {
        if (! Schema::hasTable('venta_settings')) {
            return [];
        }

        return VentaSetting::query()
            ->where('enabled', true)
            ->orderBy('store_name')
            ->get(['id', 'store_name'])
            ->map(fn (VentaSetting $s) => [
                'label' => 'Listing on ' . $s->store_name,
                'url' => route('ext.venta.listings.edit', [$s->id, $productId]),
                'icon' => 'store',
                'permission' => 'view_venta/listing',
            ])
            ->all();
    }

    public function dashboardData(): array
    {
        return [
            'ventaStoreNames' => Schema::hasTable('venta_settings')
                ? DB::table('venta_settings')->pluck('store_name', 'id')->all()
                : [],
        ];
    }

    /**
     * Resolve venta:N marketplace_source strings to the configured store name.
     */
    public function resolveSourceLabel(string $source): ?string
    {
        if (!str_starts_with($source, 'venta:')) {
            return null;
        }
        $storeId = (int) substr($source, 6);
        if ($storeId <= 0 || !Schema::hasTable('venta_settings')) {
            return null;
        }
        $name = VentaSetting::where('id', $storeId)->value('store_name');
        return $name ?: ('Venta #' . $storeId);
    }

    public function availableSourceOptions(): array
    {
        if (!Schema::hasTable('venta_settings')) {
            return [];
        }
        return VentaSetting::orderBy('id')->get(['id', 'store_name', 'brand_color'])
            ->map(fn ($s) => [
                'value'       => 'venta:' . $s->id,
                'label'       => $s->store_name ?: ('Venta #' . $s->id),
                'badge_class' => 'badge-green',
                'chart_color' => $s->brand_color ?: '#059669',
            ])->all();
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
            'api_token' => 'API token',
        ];
    }

    protected function credentialSettingsModel(): string
    {
        return \Extensions\venta\Models\VentaSetting::class;
    }

    protected function credentialIsMultiStore(): bool
    {
        return true;
    }

    public function credentialManagePermission(): string
    {
        return 'manage_venta/settings';
    }
}
