<?php

namespace Extensions\opencart;

use App\Extensions\ExtensionProvider;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Models\Catalog\OrderStatus;
use Extensions\opencart\Models\OpenCartSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OpencartExtension extends ExtensionProvider implements IntegrationProvider
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

    public function integrationCard(): ?IntegrationCard
    {
        return new IntegrationCard(
            id: $this->id,
            name: 'OpenCart',
            tagline: 'OpenCart store integration — multi-store, product sync, reviews.',
            icon: 'cart',
            accent: '#1f8fea',
            permission: 'manage_opencart',
            menu: [
                new MenuItem('Settings', 'ext.opencart.index', 'manage_opencart'),
                new MenuItem('Reviews', 'ext.opencart.reviews.index', 'manage_reviews'),
            ],
        );
    }

    public function orderTab(): ?OrderTab
    {
        // Multi-store: tab links to first enabled store; null if no stores configured.
        $firstStoreId = $this->firstEnabledStoreId();
        if ($firstStoreId === null) {
            return null;
        }

        return new OrderTab(
            id: $this->id,
            label: 'OpenCart',
            icon: 'cart',
            accent: '#1f8fea',
            routeName: 'ext.opencart.orders.index',
            routeParams: ['store' => $firstStoreId],
            permission: 'manage_opencart_orders',
            unprocessedCounter: function () {
                $pfx = (string) config('catalog.prefix');
                $orderTable = $pfx . 'order';
                if (!Schema::hasTable($orderTable)) {
                    return 0;
                }
                $pendingStatusIds = OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
                if ($pendingStatusIds->isEmpty()) {
                    return 0;
                }
                return DB::table($orderTable)
                    ->whereIn('order_status_id', $pendingStatusIds)
                    ->where('marketplace_source', 'like', 'opencart:%')
                    ->count();
            },
        );
    }

    protected function firstEnabledStoreId(): ?int
    {
        if (!Schema::hasTable('opencart_settings')) {
            return null;
        }

        return OpenCartSetting::where('enabled', true)->orderBy('id')->value('id');
    }
}
