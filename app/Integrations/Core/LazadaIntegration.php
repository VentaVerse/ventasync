<?php

namespace App\Integrations\Core;

use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Models\LazadaOrder;
use App\Models\LazadaSetting;
use Illuminate\Support\Facades\Schema;

/**
 * TEMPORARY core integration provider for Lazada — see ShopeeIntegration for
 * the same migration plan. Delete this when Lazada moves to extensions/lazada/.
 */
class LazadaIntegration implements IntegrationProvider
{
    public function integrationId(): string
    {
        return 'core:lazada';
    }

    public function integrationCard(): ?IntegrationCard
    {
        return new IntegrationCard(
            id: 'lazada',
            name: 'Lazada',
            tagline: 'Lazada marketplace integration — products, orders, returns.',
            icon: 'lazada',
            accent: '#0f146d',
            permission: 'manage_lazada',
            menu: [
                new MenuItem('Settings', 'lazada.index', 'manage_lazada'),
                new MenuItem('Product Groups', 'lazada.product-groups.index', 'manage_lazada'),
                new MenuItem('Orders', 'lazada.orders.index', 'manage_lazada_orders'),
            ],
        );
    }

    public function orderTab(): ?OrderTab
    {
        return new OrderTab(
            id: 'lazada',
            label: 'Lazada',
            icon: 'lazada',
            accent: '#0f146d',
            routeName: 'lazada.orders.index',
            permission: 'manage_lazada_orders',
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
        );
    }
}
