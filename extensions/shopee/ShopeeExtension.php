<?php

namespace Extensions\shopee;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\DashboardContributor;
use App\Integrations\Contracts\SkuSyncContributor;
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

class ShopeeExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor, DashboardContributor
{
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
}
