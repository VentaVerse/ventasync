<?php

namespace App\Integrations\Core;

use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationProvider;
use App\Integrations\MenuItem;
use App\Integrations\OrderTab;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSetting;
use Illuminate\Support\Facades\Schema;

/**
 * TEMPORARY core integration provider for Shopee.
 *
 * Shopee currently lives in app/Http/Controllers/ rather than extensions/ — this
 * provider lets the new Integrations/Orders pages discover Shopee uniformly via
 * the registry. When Shopee is moved into extensions/shopee/ (planned), delete
 * this class and let extensions/shopee/ShopeeExtension.php register itself the
 * same way the other marketplace extensions do.
 */
class ShopeeIntegration implements IntegrationProvider
{
    public function integrationId(): string
    {
        // "core:" prefix tells IntegrationRegistry to skip extension/license gates
        // since Shopee currently has no manifest. Permission gating still applies.
        return 'core:shopee';
    }

    public function integrationCard(): ?IntegrationCard
    {
        return new IntegrationCard(
            id: 'shopee',
            name: 'Shopee',
            tagline: 'Shopee marketplace integration — OAuth, products, orders, returns.',
            icon: 'shopee',
            accent: '#ee4d2d',
            permission: 'manage_shopee',
            menu: [
                new MenuItem('Settings', 'shopee.index', 'manage_shopee'),
                new MenuItem('Categories', 'shopee.categories.index', 'manage_shopee'),
                new MenuItem('Product Groups', 'shopee.product-groups.index', 'manage_shopee'),
                new MenuItem('Orders', 'shopee.orders.index', 'manage_shopee_orders'),
            ],
        );
    }

    public function orderTab(): ?OrderTab
    {
        return new OrderTab(
            id: 'shopee',
            label: 'Shopee',
            icon: 'shopee',
            accent: '#ee4d2d',
            routeName: 'shopee.orders.index',
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
        );
    }
}
