<?php

namespace Extensions\opencart;

use App\Extensions\ExtensionProvider;
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
use Extensions\opencart\Models\OpenCartProductLink;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * OpenCart is a multi-store integration. Each enabled OpenCartSetting is its
 * own card + tab; metrics are scoped via marketplace_source = "opencart:{id}".
 */
class OpencartExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor
{
    protected string $id = 'opencart';

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Extensions\opencart\Commands\OpenCartSync::class,
                \Extensions\opencart\Commands\OpenCartPush::class,
                \Extensions\opencart\Commands\OpenCartPushQty::class,
                \Extensions\opencart\Commands\OpenCartPullQty::class,
                \Extensions\opencart\Commands\OpenCartImportOrders::class,
                \Extensions\opencart\Commands\OpenCartReclassifyOrders::class,
                \Extensions\opencart\Commands\OpenCartMigrateCosts::class,
                \Extensions\opencart\Commands\ShopeeSyncReviews::class,
                \Extensions\opencart\Commands\LazadaSyncReviews::class,
                \Extensions\opencart\Commands\OpenCartPushReviews::class,
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
        foreach ($this->enabledStores() as $store) {
            $stores[] = new StoreLink(
                id: $this->id . ':' . $store->id,
                label: $store->store_name,
                menu: [
                    new MenuItem('Product Groups', 'ext.opencart.product-groups.index', 'manage_opencart', null, ['store' => $store->id]),
                    new MenuItem('Orders', 'ext.opencart.orders.index', 'manage_opencart_orders', null, ['store' => $store->id]),
                ],
            );
        }

        return [
            new IntegrationCard(
                id: $this->id,
                name: 'OpenCart',
                tagline: 'Multi-store OpenCart — add stores, sync products, push reviews.',
                icon: 'cart',
                accent: '#1f8fea',
                permission: 'manage_opencart',
                menu: [
                    new MenuItem('Settings', 'ext.opencart.index', 'manage_opencart'),
                    new MenuItem('Reviews', 'ext.opencart.reviews.index', 'manage_reviews'),
                ],
                stores: $stores,
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
     * @return iterable<\Extensions\opencart\Models\OpenCartSetting>
     */
    protected function enabledStores(): iterable
    {
        if (!Schema::hasTable('opencart_settings')) {
            return [];
        }
        return OpenCartSetting::where('enabled', true)->orderBy('id')->get();
    }

    protected function buildOrderTab(int $storeId, string $storeName): OrderTab
    {
        $marketplaceSource = 'opencart:' . $storeId;
        $orderTable = (string) config('catalog.prefix') . 'order';
        $productTable = (string) config('catalog.prefix') . 'order_product';

        return new OrderTab(
            id: $this->id . ':' . $storeId,
            label: $storeName,
            icon: 'cart',
            accent: '#1f8fea',
            routeName: 'ext.opencart.orders.index',
            permission: 'manage_opencart_orders',
            routeParams: ['store' => $storeId],
            unprocessedCounter: function () use ($marketplaceSource, $orderTable) {
                if (!Schema::hasTable($orderTable)) {
                    return 0;
                }
                $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
                if ($pendingStatusIds->isEmpty()) {
                    return 0;
                }
                return DB::table($orderTable)
                    ->whereIn('order_status_id', $pendingStatusIds)
                    ->where('marketplace_source', $marketplaceSource)
                    ->count();
            },
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
                return DB::table($productTable . ' as p')
                    ->join($orderTable . ' as o', 'o.order_id', '=', 'p.order_id')
                    ->where('o.marketplace_source', $marketplaceSource)
                    ->where('o.date_added', '>=', now()->startOfDay())
                    ->whereNotNull('p.model')
                    ->where('p.model', '!=', '')
                    ->groupBy('p.model')
                    ->orderByDesc('qty_sold')
                    ->limit($limit)
                    ->select(
                        'p.model as sku',
                        DB::raw('MAX(p.name) as name'),
                        DB::raw('SUM(p.quantity) as qty_sold'),
                        DB::raw('SUM(p.total) as revenue'),
                    )
                    ->get()
                    ->map(fn ($r) => new TopProduct(
                        sku: (string) $r->sku,
                        name: (string) ($r->name ?? $r->sku),
                        imageUrl: null,
                        qtySold: (int) $r->qty_sold,
                        revenue: (float) $r->revenue,
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
     * React to a SKU change on a catalog product by re-pushing the product
     * to every enabled OpenCart store that has it linked. The push command
     * already handles SKU+model+option-value updates wholesale, so this is
     * "if any store has this product, refresh it."
     */
    public function pushSkuChanges(int $productId, array $skuChanges): ?string
    {
        if (!Schema::hasTable('opencart_settings')) {
            return null;
        }

        $stores = OpenCartSetting::where('enabled', true)->get();
        if ($stores->isEmpty()) {
            return null;
        }

        $results = [];
        foreach ($stores as $setting) {
            $link = OpenCartProductLink::where('opencart_setting_id', $setting->id)
                ->where('product_id', $productId)
                ->first();
            if (!$link) {
                continue;
            }

            try {
                $client = new OpenCartClient($setting);
                $sync = new OpenCartProductSync($client, $setting);
                $result = $sync->push([$productId]);
                $ok = ($result['failed'] ?? 0) === 0;
                $results[] = $setting->store_name . ': ' . ($ok ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('SKU sync to OpenCart failed', [
                    'store' => $setting->store_name,
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $setting->store_name . ': Error';
            }
        }

        return empty($results) ? null : 'OpenCart: ' . implode(', ', $results);
    }
}
