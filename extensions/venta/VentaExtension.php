<?php

namespace Extensions\venta;

use App\Extensions\ExtensionProvider;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Models\Catalog\OrderStatus;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VentaExtension extends ExtensionProvider implements IntegrationProvider
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
            name: 'Venta Store',
            tagline: 'Venta ecommerce — multi-store order sync, inventory, reviews.',
            icon: 'shop',
            accent: '#7c3aed',
            permission: 'manage_venta',
            menu: [
                new MenuItem('Settings', 'ext.venta.index', 'manage_venta'),
            ],
        );
    }

    public function orderTab(): ?OrderTab
    {
        $firstStoreId = $this->firstEnabledStoreId();
        if ($firstStoreId === null) {
            return null;
        }

        return new OrderTab(
            id: $this->id,
            label: 'Venta',
            icon: 'shop',
            accent: '#7c3aed',
            routeName: 'ext.venta.orders.index',
            routeParams: ['store' => $firstStoreId],
            permission: 'manage_venta_orders',
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
                    ->where('marketplace_source', 'like', 'venta:%')
                    ->count();
            },
        );
    }

    protected function firstEnabledStoreId(): ?int
    {
        if (!Schema::hasTable('venta_settings')) {
            return null;
        }

        return VentaSetting::where('enabled', true)->orderBy('id')->value('id');
    }
}
