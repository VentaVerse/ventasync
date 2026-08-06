<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\ChannelWorkspace;
use Extensions\lazada\LazadaExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 2, Task 1: Lazada gets what Shopee already has. Modeled directly on
 * ChannelWorkspaceTest's Shopee fixtures, own idempotent extension setup and
 * own uniquely-named user groups so this suite never collides with Shopee's
 * when both run in the same process.
 */
class LazadaWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Holds both real Lazada read permissions and nothing else: a genuine
     * read-only operator, not an admin stand-in. view_lazada is the card
     * gate; view_lazada_orders is what the orders/returns routes demand
     * (perm:view_lazada_orders|manage_lazada_orders).
     */
    private function lazadaOperator(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Workspace Operators']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada', 'view_lazada_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Narrower operator: view_lazada_orders and nothing else. NOT the
     * card-level view_lazada that IntegrationRegistry::visibleCards() wants,
     * so this user cannot open /integrations/lazada at all, only the orders
     * route the perm: middleware on that route itself allows. Parent/child
     * permission cascades are removed from this app, so holding the orders
     * permission grants nothing about the channel card.
     */
    private function lazadaOrdersOnlyOperator(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Workspace Order Desk']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Same idempotent pattern ChannelWorkspaceTest::setUp() uses for Shopee:
     * install+enable the extension row (Extension::updateOrCreate, so this
     * is a no-op if it already exists), then register the provider directly
     * because ExtensionServiceProvider::boot() has already run by the time
     * this method executes and only registers providers for rows that were
     * already enabled at that moment. $this->app->register() is idempotent
     * too (the container returns the already-registered instance rather than
     * double-booting), so this is safe whether Lazada was already live or
     * not.
     *
     * refreshNameLookups() is load-bearing: registering the provider here
     * loads all of Lazada's routes into the RouteCollection, but the
     * separate name-lookup table Route::has()/getByName() read is only
     * rebuilt on demand. Without this call every route added after it was
     * last built is invisible to ChannelWorkspace::allows(), which calls
     * Route::has() as its first gate, so every positive test would fail and
     * every negative test would pass for the wrong reason.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');

        $this->app->register(LazadaExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function lazadaCard(): \App\Integrations\IntegrationCard
    {
        $registry = app(IntegrationRegistry::class);

        foreach ($registry->all() as $provider) {
            foreach ($provider->integrationCards() as $card) {
                if ($card->id === 'lazada') {
                    return $card;
                }
            }
        }

        $this->fail('Lazada did not register an IntegrationCard.');
    }

    public function test_lazada_menu_covers_every_workspace_destination(): void
    {
        $routes = array_map(
            fn ($i) => $i->routeName,
            $this->lazadaCard()->menu
        );

        // Listings, Returns and Brands were missing before this task, the
        // same gap Shopee had.
        $this->assertEqualsCanonicalizing([
            'ext.lazada.orders.index',
            'ext.lazada.orders.returns',
            'ext.lazada.products.index',
            'ext.lazada.product-groups.index',
            'ext.lazada.categories.index',
            'ext.lazada.brands.index',
            'ext.lazada.index',
        ], $routes);
    }

    public function test_every_lazada_menu_item_declares_a_known_group(): void
    {
        foreach ($this->lazadaCard()->menu as $item) {
            $this->assertContains(
                $item->group,
                ['Sell', 'Catalogue', 'Channel'],
                "Menu item {$item->label} has group ".var_export($item->group, true)
            );
        }
    }

    public function test_lazada_orders_menu_item_keeps_its_default_tab_param(): void
    {
        foreach ($this->lazadaCard()->menu as $item) {
            if ($item->routeName === 'ext.lazada.orders.index') {
                $this->assertSame(['tab' => 'TO_SHIP'], $item->routeParams);

                return;
            }
        }

        $this->fail('Orders menu item not found.');
    }

    public function test_groups_are_ordered_and_bucketed(): void
    {
        $user = $this->lazadaOperator();
        $groups = app(ChannelWorkspace::class)->groups($this->lazadaCard(), $user);

        $this->assertSame(
            ['Sell', 'Catalogue', 'Channel'],
            array_column($groups, 'label')
        );
        $this->assertSame(
            ['Orders', 'Returns'],
            array_column($groups[0]['items'], 'label')
        );
        $this->assertSame(
            ['Listings', 'Product Groups', 'Categories', 'Brands'],
            array_column($groups[1]['items'], 'label')
        );
        $this->assertSame(
            ['Settings'],
            array_column($groups[2]['items'], 'label')
        );
    }

    public function test_every_destination_is_linkable(): void
    {
        $user = $this->lazadaOperator();
        $groups = app(ChannelWorkspace::class)->groups($this->lazadaCard(), $user);

        $urls = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                // route() throwing here is the actual failure this guards.
                $this->assertIsString($item['url']);
                $this->assertStringStartsWith('http', $item['url']);
                $urls[] = $item['url'];
            }
        }

        // Without this the whole test passes vacuously when groups() returns
        // nothing. Seven is every Lazada destination, so this also proves
        // the operator permissions above are sufficient to see all of them.
        $this->assertCount(7, $urls);
    }

    public function test_workspace_renders_for_a_permitted_user(): void
    {
        $user = $this->lazadaOperator();

        $this->actingAs($user)
            ->get('/integrations/lazada')
            ->assertOk()
            ->assertSee('Lazada')
            ->assertSee('Overview');
    }

    public function test_an_orders_only_operator_reaches_the_orders_page(): void
    {
        $user = $this->lazadaOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/lazada/orders');

        $response->assertOk();
        $response->assertSee('x-cnav', false);
    }

    /**
     * The narrow user gets a correspondingly narrow sidebar: Sell survives
     * (both its items declare view_lazada_orders), Catalogue and Channel do
     * not (every item in them declares view_lazada, which this operator does
     * not hold).
     */
    public function test_an_orders_only_operator_sees_only_the_sell_group(): void
    {
        $user = $this->lazadaOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/lazada/orders');

        $response->assertOk();

        $response->assertSee('<span class="x-cnav__group-label">Sell</span>', false);
        $response->assertSee('>Orders</a>', false);
        $response->assertSee('>Returns</a>', false);

        $response->assertDontSee('<span class="x-cnav__group-label">Catalogue</span>', false);
        $response->assertDontSee('<span class="x-cnav__group-label">Channel</span>', false);
        $response->assertDontSee('>Listings</a>', false);
        $response->assertDontSee('>Product Groups</a>', false);
        $response->assertDontSee('>Categories</a>', false);
        $response->assertDontSee('>Brands</a>', false);
        $response->assertDontSee('>Settings</a>', false);
    }

    public static function lazadaPageProvider(): array
    {
        return [
            'orders' => ['/lazada/orders'],
            'returns' => ['/lazada/orders/returns'],
            'listings' => ['/lazada/products'],
            'product groups' => ['/lazada/product-groups'],
            'categories' => ['/lazada/categories'],
            'brands' => ['/lazada/brands'],
            'settings' => ['/lazada'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lazadaPageProvider')]
    public function test_lazada_pages_render_inside_the_workspace(string $path): void
    {
        // lazadaOperator() holds exactly view_lazada and view_lazada_orders,
        // which is every permission any of these seven routes' perm:
        // middleware demands, so a real read-only operator proves all seven
        // render inside the workspace.
        $user = $this->lazadaOperator();

        $response = $this->actingAs($user)->get($path);

        $response->assertOk();
        $response->assertSee('x-cnav', false);
        $response->assertSee('All channels');
        // data-nav, not data-nav-group: partials/nav.blade.php always renders
        // the outer <nav class="x-nav" data-nav> wrapper, so its absence is
        // real evidence the global sidebar did not render.
        $response->assertDontSee('data-nav', false);
    }

    /**
     * The layout owns the "Integrations / Lazada" trail, so each view
     * supplies only its leaf. Before this task the views carried the full
     * trail themselves, which would have read "Integrations / Lazada /
     * Integrations / Lazada / Orders" on every one of the seven pages, the
     * exact bug Shopee's own conversion hit and fixed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lazadaPageProvider')]
    public function test_the_breadcrumb_trail_is_not_duplicated(string $path): void
    {
        $html = $this->actingAs($this->lazadaOperator())->get($path)->getContent();

        $crumb = preg_match('/<div class="x-topbar__crumb">(.*?)<\/div>/s', $html, $m)
            ? strip_tags($m[1])
            : $this->fail('No breadcrumb rendered on '.$path);

        $this->assertSame(
            1,
            substr_count($crumb, 'Integrations'),
            'Breadcrumb repeats the trail on '.$path.': '.preg_replace('/\s+/', ' ', trim($crumb))
        );
        $this->assertSame(
            1,
            substr_count($crumb, 'Lazada'),
            'Breadcrumb repeats the channel on '.$path.': '.preg_replace('/\s+/', ' ', trim($crumb))
        );
    }

    /**
     * The cross-marketplace tab strip belongs to the unified Orders page.
     * Inside a seller center you see only that marketplace.
     *
     * Checked here on Returns, Listings, Product Groups, Categories, Brands
     * and Settings, not Orders. orders/index.blade.php still includes
     * integrations.partials._tab_strip at this point: Shopee's own history
     * shows the identical carryover (commit 10458135, right after Shopee's
     * workspace conversion, still had the include; it was only dropped in
     * d9982985 when Orders was rebuilt on the shared anatomy). Stripping it
     * now would be a content edit beyond breadcrumb/extends, which this task
     * does not do, and that removal is Task 2's job. Asserting it away here
     * would either fail honestly or require silently editing content this
     * task is not scoped to touch, so this test covers the six destinations
     * where the rule already holds today and leaves Orders to Task 2's own
     * regression test once the strip is actually gone.
     */
    public function test_the_seller_center_shows_no_other_marketplace(): void
    {
        $response = $this->actingAs($this->lazadaOperator())->get('/lazada/orders/returns');

        $response->assertOk();
        $response->assertDontSee('All marketplaces');
        $response->assertDontSee('ord-tab-head', false);
    }

    public function test_the_channels_group_offers_lazada_and_lands_on_the_seller_center(): void
    {
        $this->actingAs($this->lazadaOperator());

        $channels = collect(\App\Support\Navigation::groups())
            ->firstWhere('label', 'Channels');

        $this->assertNotNull($channels, 'The nav has no Channels group.');

        $labels = array_column($channels['items'], 'label');
        $this->assertContains('All channels', $labels);
        $this->assertContains('Lazada', $labels);

        $lazada = collect($channels['items'])->firstWhere('label', 'Lazada');
        $this->assertSame('integrations.workspace', $lazada['route']);
        $this->assertSame(
            route('integrations.workspace', ['channel' => 'lazada']),
            route($lazada['route'], $lazada['params'])
        );
    }

    public function test_the_board_links_into_the_lazada_workspace(): void
    {
        $user = $this->lazadaOperator();

        $this->actingAs($user)
            ->get('/integrations')
            ->assertOk()
            ->assertSee(route('integrations.workspace', ['channel' => 'lazada']), false);
    }

}
