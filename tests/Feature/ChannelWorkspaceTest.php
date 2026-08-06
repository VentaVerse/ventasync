<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use Extensions\shopee\ShopeeExtension;
use Tests\TestCase;

class ChannelWorkspaceTest extends TestCase
{
    /**
     * Establish this test's own precondition instead of inheriting whatever
     * the `extensions` table happens to hold when this method runs. Without
     * this, the test only passes when some earlier test (or a manual fixture)
     * left Shopee enabled - order-dependent and not reproducible on a clean
     * database or under `php artisan test` with a different run order.
     *
     * Idempotent by construction:
     * - ExtensionManager::install() is Extension::updateOrCreate() keyed by
     *   id, so calling it when Shopee is already installed updates the same
     *   row rather than inserting a duplicate.
     * - ExtensionManager::enable() is a plain ->update(['enabled' => true])
     *   on that same row - a no-op when it is already enabled.
     *
     * Enabling the DB row alone is not sufficient. Extension providers are
     * only registered into IntegrationRegistry once, inside
     * ExtensionServiceProvider::boot(), which has already finished by the
     * time this method runs (Tests\TestCase::setUp() -> parent::setUp() ->
     * refreshApplication() boots every provider before control returns
     * here). If Shopee's row did not exist yet at that moment - e.g. an
     * earlier RefreshDatabase test in the same process wiped the table -
     * ExtensionServiceProvider never registered
     * Extensions\shopee\ShopeeExtension, and enabling the row now is too
     * late to trigger that registration on its own.
     *
     * $this->app->register() closes that gap and stays idempotent doing it:
     * Laravel's container tracks already-registered providers and returns
     * the existing instance without re-running register()/boot() when one
     * is found - which is exactly what a normal boot that already picked up
     * Shopee produces, so nothing double-registers. When no instance is
     * registered yet, this call registers and immediately boots a real
     * ShopeeExtension right now (the app is already booted, so
     * Application::register() boots synchronously), which runs its real
     * boot() and therefore its own IntegrationRegistry::register($this) -
     * the same call path production uses. Either way,
     * shopeeCard()->menu below comes from the genuine
     * ShopeeExtension::integrationCards(), never a stand-in - so these
     * tests still fail honestly if the real menu regresses.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');

        $this->app->register(ShopeeExtension::class);
    }

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
