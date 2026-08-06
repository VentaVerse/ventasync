<?php

namespace Tests\Feature;

use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use Tests\TestCase;

class ChannelWorkspaceTest extends TestCase
{
    private function shopeeCard(): \App\Integrations\IntegrationCard
    {
        $registry = app(IntegrationRegistry::class);

        foreach ($registry->all() as $provider) {
            foreach ($provider->integrationCards() as $card) {
                if ($card->id === 'shopee') {
                    return $card;
                }
            }
        }

        $this->fail('Shopee did not register an IntegrationCard.');
    }

    public function test_menu_item_group_defaults_to_null(): void
    {
        $item = new MenuItem('Anything', 'dashboard');

        $this->assertNull($item->group);
    }

    public function test_shopee_menu_covers_every_workspace_destination(): void
    {
        $routes = array_map(
            fn (MenuItem $i) => $i->routeName,
            $this->shopeeCard()->menu
        );

        // Listings and Returns were missing before this task.
        $this->assertEqualsCanonicalizing([
            'ext.shopee.orders.index',
            'ext.shopee.orders.returns',
            'ext.shopee.products.index',
            'ext.shopee.product-groups.index',
            'ext.shopee.categories.index',
            'ext.shopee.logistics.index',
            'ext.shopee.index',
        ], $routes);
    }

    public function test_every_shopee_menu_item_declares_a_known_group(): void
    {
        foreach ($this->shopeeCard()->menu as $item) {
            $this->assertContains(
                $item->group,
                ['Sell', 'Catalogue', 'Channel'],
                "Menu item {$item->label} has group ".var_export($item->group, true)
            );
        }
    }
}
