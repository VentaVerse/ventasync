<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\ChannelWorkspace;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user holding exactly the two Shopee read permissions and nothing
     * else. Built here rather than looked up, because `ventasync_test` has
     * no seeded admin and, more importantly, a test that leans on whatever
     * permissions some existing account happens to hold is not asserting
     * anything about the gate it claims to test.
     */
    private function shopeeOperator(): User
    {
        $group = UserGroup::create(['name' => 'Shopee Operators']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee', 'view_shopee_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

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

        // Registering the provider above loads all 80 Shopee routes into the
        // RouteCollection, but the collection keeps a SEPARATE name lookup
        // table that is only rebuilt on demand. Routes added after it was
        // last built are invisible to getByName(), and therefore to
        // Route::has(), even though they are in the collection and would
        // match a real request.
        //
        // ChannelWorkspace::allows() calls Route::has(), so without this the
        // gate rejects every Shopee destination and the positive tests fail
        // while the negative ones pass for the wrong reason.
        //
        // Production never hits this: extensions register during boot, long
        // before anything looks a route up by name.
        $this->app['router']->getRoutes()->refreshNameLookups();
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

    public function test_groups_are_ordered_and_bucketed(): void
    {
        $user = $this->shopeeOperator();
        $workspace = app(ChannelWorkspace::class);
        $groups = $workspace->groups($this->shopeeCard(), $user);

        $this->assertSame(
            ['Sell', 'Catalogue', 'Channel'],
            array_column($groups, 'label')
        );
        $this->assertSame(
            ['Orders', 'Returns'],
            array_column($groups[0]['items'], 'label')
        );
    }

    public function test_every_destination_is_linkable(): void
    {
        $user = $this->shopeeOperator();
        $groups = app(ChannelWorkspace::class)->groups($this->shopeeCard(), $user);

        $urls = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                // route() throwing here is the actual failure mode this guards.
                $this->assertIsString($item['url']);
                $this->assertStringStartsWith('http', $item['url']);
                $urls[] = $item['url'];
            }
        }

        // Without this the whole test passes vacuously when groups() returns
        // nothing at all: the loop body never runs and no assertion executes.
        // Seven is every Shopee destination, so this also proves the operator
        // permissions above are sufficient to see all of them.
        $this->assertCount(7, $urls);
    }

    public function test_a_route_needing_a_uri_parameter_is_rejected(): void
    {
        // ext.shopee.orders.show is shopee/orders/{orderSn}: Route::has() is
        // true and route() throws without the parameter. It must not be
        // linkable.
        //
        // Every other reason allows() could return false is deliberately
        // ruled out, so the parameter guard is the only thing that can reject
        // this. The route belongs to Shopee, which setUp() enables, so it
        // genuinely exists here. The operator genuinely holds its permission.
        // Pick a route from a disabled extension instead and this test passes
        // at the Route::has() check whether or not the guard exists at all,
        // which is precisely the vacuous assertion this branch has shipped
        // once before.
        $item = new MenuItem('Bad', 'ext.shopee.orders.show', 'view_shopee_orders', null, [], 'Channel');
        $user = $this->shopeeOperator();

        $this->assertFalse(app(ChannelWorkspace::class)->allows($item, $user));
    }

    public function test_an_item_is_hidden_when_the_registry_permission_is_missing(): void
    {
        $item = new MenuItem('Orders', 'ext.shopee.orders.index', 'a_permission_nobody_holds', null, [], 'Sell');
        $user = $this->shopeeOperator();

        $this->assertFalse(app(ChannelWorkspace::class)->allows($item, $user));
    }

    public function test_an_item_is_visible_when_both_gates_pass(): void
    {
        // The mirror of the test above. Without this one, a totally broken
        // allows() that returns false unconditionally would still pass.
        $item = new MenuItem('Orders', 'ext.shopee.orders.index', 'view_shopee_orders', null, [], 'Sell');
        $user = $this->shopeeOperator();

        $this->assertTrue(app(ChannelWorkspace::class)->allows($item, $user));
    }

    public function test_a_guest_gets_nothing(): void
    {
        $item = new MenuItem('Orders', 'ext.shopee.orders.index', 'view_shopee_orders', null, [], 'Sell');

        $this->assertFalse(app(ChannelWorkspace::class)->allows($item, null));
    }
}
