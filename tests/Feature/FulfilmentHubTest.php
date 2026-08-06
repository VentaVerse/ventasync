<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fulfilment Hub (spec docs/superpowers/specs/2026-08-07-fulfilment-hub.md
 * section 7). Own copy of ChannelWorkspaceTest's fixture pattern rather than
 * a shared trait, so this file stays readable standalone and a change to one
 * suite's setUp() cannot silently alter the other's.
 */
class FulfilmentHubTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user holding exactly the two Shopee read permissions and nothing
     * else - sees the Shopee tab (permission: view_shopee_orders) and, since
     * this test targets the hub rather than the seller center, also the
     * card-level view_shopee, matching the plan's "a user holding
     * view_shopee + view_shopee_orders" for tests 1, 2 and 6.
     */
    private function shopeeOperator(): User
    {
        $group = UserGroup::create(['name' => 'Fulfilment Hub Operators']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee', 'view_shopee_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Holds the manage tier for Shopee orders. manage_shopee_orders implies
     * view_shopee_orders (User::hasPermission()'s manage-implies-view
     * cascade), so this user sees the Shopee tab AND its command bar.
     */
    private function shopeeManager(): User
    {
        $group = UserGroup::create(['name' => 'Fulfilment Hub Managers']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee', 'manage_shopee_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * The security-relevant fixture for test 5. Holds no Shopee permission
     * whatsoever, so the hub's channel gate is the only thing standing
     * between this user and Shopee's order data.
     *
     * Deliberately NOT built with only an unrelated core permission (e.g.
     * manage_products alone): IntegrationController@orders returns the
     * empty state before it ever parses ?channel when visibleOrderTabs()
     * comes back empty, so a user with zero visible tabs never reaches the
     * membership check this test exists to guard - the mutation described
     * in the task (bypassing the $selectedTab lookup for 'shopee') would go
     * completely unexercised and this test would stay green whether or not
     * the gate existed. Registering Lazada and granting its own order
     * permission keeps visibleOrderTabs() non-empty (a real Lazada tab
     * renders in the strip, Summary is genuinely reached, not the empty
     * state) while leaving every Shopee permission absent, so the
     * ?channel=shopee request below actually walks the code path that
     * decides whether 'shopee' is a member of this user's visible tabs.
     */
    private function fulfilmentOutsider(): User
    {
        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $group = UserGroup::create(['name' => 'Fulfilment Hub Outsiders']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada_orders', 'manage_products'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Same idempotent-install rationale as ChannelWorkspaceTest::setUp():
     * ExtensionManager::install()/enable() alone do not re-register the
     * provider into IntegrationRegistry once ExtensionServiceProvider::boot()
     * has already run, and refreshNameLookups() is needed because routes
     * registered by a provider booted this late are invisible to
     * Route::has()/route() until the name lookup table is rebuilt.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');

        $this->app->register(ShopeeExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    public function test_default_render_shows_summary_and_the_shopee_card(): void
    {
        $user = $this->shopeeOperator();

        $response = $this->actingAs($user)->get('/integrations/orders');

        $response->assertOk();
        // Stable Summary marker: this heading only renders in the Summary
        // branch of resources/views/integrations/orders.blade.php.
        $response->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
        // The strip includes a Shopee card whose link switches the panel in
        // place, exactly this URL.
        $response->assertSee(route('integrations.orders', ['channel' => 'shopee']), false);
    }

    public function test_selecting_shopee_switches_the_panel_in_place(): void
    {
        $user = $this->shopeeOperator();

        $response = $this->actingAs($user)->get('/integrations/orders?channel=shopee');

        $response->assertOk();
        $response->assertSee('id="shopee-orders-page"', false);
        $response->assertDontSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
    }

    public function test_manager_sees_the_command_bar_on_the_hub_panel(): void
    {
        $manager = $this->shopeeManager();

        $response = $this->actingAs($manager)->get('/integrations/orders?channel=shopee');

        $response->assertOk();
        $response->assertSee('id="btnFetchOrders"', false);
    }

    public function test_viewer_does_not_see_the_command_bar_on_the_hub_panel(): void
    {
        $viewer = $this->shopeeOperator();

        $response = $this->actingAs($viewer)->get('/integrations/orders?channel=shopee');

        $response->assertOk();
        $response->assertDontSee('id="btnFetchOrders"', false);
    }

    public function test_invalid_channel_falls_back_to_summary(): void
    {
        $user = $this->shopeeOperator();

        $response = $this->actingAs($user)->get('/integrations/orders?channel=nonsense');

        $response->assertOk();
        $response->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
        $response->assertDontSee('id="shopee-orders-page"', false);
    }

    /**
     * The security-relevant test. See fulfilmentOutsider()'s docblock for
     * why this user must have a non-Shopee tab visible rather than none at
     * all - only that shape actually exercises the membership check the
     * mutation below removes.
     */
    public function test_unauthorised_channel_falls_back_to_summary(): void
    {
        $user = $this->fulfilmentOutsider();

        // Guards the premise: if this ever stops holding, the assertions
        // below would start passing for the wrong reason.
        $this->assertFalse($user->hasPermission('view_shopee'));
        $this->assertFalse($user->hasPermission('view_shopee_orders'));
        $this->assertTrue($user->hasPermission('view_lazada_orders'));

        $response = $this->actingAs($user)->get('/integrations/orders?channel=shopee');

        $response->assertOk();
        $response->assertDontSee('id="shopee-orders-page"', false);
        // Summary, not the empty state: this user does see a tab (Lazada),
        // so the request must land on the aggregate view, not the "no
        // marketplaces visible" branch.
        $response->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
    }

    public function test_seller_center_shows_no_card_strip(): void
    {
        $user = $this->shopeeOperator();

        $response = $this->actingAs($user)->get('/shopee/orders');

        $response->assertOk();
        $response->assertDontSee('x-fh-strip', false);
    }

    public function test_hub_panel_shows_the_card_strip(): void
    {
        $user = $this->shopeeOperator();

        $response = $this->actingAs($user)->get('/integrations/orders?channel=shopee');

        $response->assertOk();
        $response->assertSee('x-fh-strip', false);
    }

    public function test_panel_links_keep_hub_context(): void
    {
        $user = $this->shopeeOperator();

        $response = $this->actingAs($user)->get('/integrations/orders?channel=shopee');

        $response->assertOk();
        // The filter form posts back to the hub's own route, not Shopee's
        // seller-center route.
        $response->assertSee(
            '<form method="GET" action="' . e(route('integrations.orders')) . '" class="x-filters" id="orders-filter">',
            false
        );
        // Every self-link (filter form's hidden input, segment links via
        // $panelSelfUrl) carries channel=shopee so the context survives a
        // filter change or a page reload.
        $response->assertSee('<input type="hidden" name="channel" value="shopee">', false);
        // Segment links specifically: tab= only ever appears on the status
        // segment, so a URL carrying both tab= and channel=shopee proves the
        // SEGMENT keeps context, which the strip card (also channel=shopee)
        // and the hidden input above cannot prove for it. The final review
        // flagged the bare channel=shopee assertion as satisfiable by the
        // card alone.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*integrations\/orders\?[^"]*(?:tab=[^"]*channel=shopee|channel=shopee[^"]*tab=)[^"]*"/',
            $response->getContent()
        );
    }

    public function test_a_user_with_no_visible_tabs_gets_the_empty_state(): void
    {
        // No Shopee (or any channel) permission at all, so visibleOrderTabs
        // is empty and the controller's empty($tabs) branch renders. The
        // final review found this branch had no automated coverage.
        $group = UserGroup::create(['name' => 'Fulfilment No Tabs']);
        $group->permissions()->attach(
            Permission::where('key', 'manage_products')->firstOrFail()->id
        );
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $response = $this->actingAs($user)->get('/integrations/orders');

        $response->assertOk();
        $response->assertSee('No marketplace orders available');
        $response->assertDontSee('id="shopee-orders-page"', false);
    }
}
