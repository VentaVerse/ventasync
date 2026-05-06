<?php

namespace Extensions\tiktok;

use App\Extensions\ExtensionProvider;
use App\Integrations\Contracts\SkuSyncContributor;
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

class TiktokExtension extends ExtensionProvider implements IntegrationProvider, SkuSyncContributor
{
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
}
