<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\OpencartExtension;
use Extensions\shopee\ShopeeExtension;
use Extensions\tiktok\TiktokExtension;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\VentaExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    // ------------------------------------------------------------------
    // Task 3 (round 2): Lazada joins Shopee as a second channel whose
    // orders render in place on the hub, through IntegrationController@
    // orders's now-generalised self::ORDER_PANEL_SERVICES map rather than
    // a shopee-only literal. Own fixtures below register the Lazada
    // extension explicitly, because setUp() only registers Shopee.
    // ------------------------------------------------------------------

    private function registerLazada(): void
    {
        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /**
     * Card + orders read tier, the same combo shopeeOperator() holds for
     * Shopee: view_lazada is the card gate, view_lazada_orders is what
     * visibleOrderTabs() checks per tab.
     */
    private function lazadaOperator(): User
    {
        $this->registerLazada();

        $group = UserGroup::create(['name' => 'Fulfilment Hub Lazada Operators']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada', 'view_lazada_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * manage_lazada_orders implies view_lazada_orders (the same
     * manage-implies-view cascade shopeeManager() relies on), so this user
     * sees the Lazada tab AND its command bar.
     */
    private function lazadaManager(): User
    {
        $this->registerLazada();

        $group = UserGroup::create(['name' => 'Fulfilment Hub Lazada Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada', 'manage_lazada_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * shopeeOperator() with Lazada also registered (but never granted), so
     * the Lazada tab genuinely exists in the registry - just not in this
     * user's own visibleOrderTabs() list. Without registering Lazada here,
     * 'lazada' would never appear in ANY user's $tabs regardless of the
     * membership check's correctness, and the reverse-direction mutation
     * (bypassing the loop for a channel that exists nowhere) would go
     * unexercised the same way fulfilmentOutsider()'s docblock explains for
     * the Shopee direction.
     */
    private function shopeeOperatorWithLazadaRegistered(): User
    {
        $this->registerLazada();

        return $this->shopeeOperator();
    }

    public function test_default_render_includes_a_lazada_card_that_switches_in_place(): void
    {
        $user = $this->lazadaOperator();

        $response = $this->actingAs($user)->get('/integrations/orders');

        $response->assertOk();
        // Lazada's hub card stops linking out to /lazada/orders once its
        // panel is extracted: the card strip switches the panel in place,
        // exactly like Shopee's card, keyed off the same panelChannels list
        // rather than a shopee-only literal.
        $response->assertSee(route('integrations.orders', ['channel' => 'lazada']), false);
        $response->assertDontSee(route('ext.lazada.orders.index', ['tab' => 'TO_SHIP']), false);
    }

    public function test_selecting_lazada_switches_the_panel_in_place(): void
    {
        $user = $this->lazadaOperator();

        $response = $this->actingAs($user)->get('/integrations/orders?channel=lazada');

        $response->assertOk();
        $response->assertSee('id="lazada-orders-page"', false);
        $response->assertDontSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
    }

    public function test_lazada_manager_sees_the_command_bar_on_the_hub_panel(): void
    {
        $manager = $this->lazadaManager();

        $response = $this->actingAs($manager)->get('/integrations/orders?channel=lazada');

        $response->assertOk();
        $response->assertSee('id="btnFetchOrders"', false);
    }

    public function test_lazada_viewer_does_not_see_the_command_bar_on_the_hub_panel(): void
    {
        $viewer = $this->lazadaOperator();

        $response = $this->actingAs($viewer)->get('/integrations/orders?channel=lazada');

        $response->assertOk();
        $response->assertDontSee('id="btnFetchOrders"', false);
    }

    /**
     * The reverse of test_unauthorised_channel_falls_back_to_summary: a
     * user holding only Shopee permissions must not be able to select
     * Lazada's panel either. Together the two tests prove the membership
     * gate is genuinely per-channel, not a Shopee-only check that happens
     * to also block everything else, and that generalising it to
     * self::ORDER_PANEL_SERVICES did not loosen the Shopee side.
     */
    public function test_shopee_only_user_requesting_lazada_falls_back_to_summary(): void
    {
        $user = $this->shopeeOperatorWithLazadaRegistered();

        // Guards the premise: if this ever stops holding, the assertion
        // below would start passing for the wrong reason.
        $this->assertFalse($user->hasPermission('view_lazada'));
        $this->assertFalse($user->hasPermission('view_lazada_orders'));
        $this->assertTrue($user->hasPermission('view_shopee_orders'));

        $response = $this->actingAs($user)->get('/integrations/orders?channel=lazada');

        $response->assertOk();
        $response->assertDontSee('id="lazada-orders-page"', false);
        // Summary, not the empty state: this user does see a tab (Shopee),
        // so the request must land on the aggregate view.
        $response->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
    }

    public function test_lazada_panel_links_keep_hub_context(): void
    {
        $user = $this->lazadaOperator();

        $response = $this->actingAs($user)->get('/integrations/orders?channel=lazada');

        $response->assertOk();
        // The filter form posts back to the hub's own route, not Lazada's
        // seller-center route.
        $response->assertSee(
            '<form method="GET" action="' . e(route('integrations.orders')) . '" class="x-filters" id="orders-filter">',
            false
        );
        // Every self-link (filter form's hidden input, segment links via
        // $panelSelfUrl) carries channel=lazada so the context survives a
        // filter change or a page reload.
        $response->assertSee('<input type="hidden" name="channel" value="lazada">', false);
        // Segment links specifically: tab= only ever appears on the status
        // segment, so a URL carrying both tab= and channel=lazada proves the
        // SEGMENT keeps context, not only the strip card and the hidden
        // input above (neither of which can prove the segment on its own).
        $this->assertMatchesRegularExpression(
            '/href="[^"]*integrations\/orders\?[^"]*(?:tab=[^"]*channel=lazada|channel=lazada[^"]*tab=)[^"]*"/',
            $response->getContent()
        );
    }

    // ------------------------------------------------------------------
    // Round 2, Task 2: TikTok becomes the third channel rendering in place,
    // through the same self::ORDER_PANEL_SERVICES map. Own fixtures again,
    // because setUp() only registers Shopee.
    // ------------------------------------------------------------------

    private function registerTiktok(): void
    {
        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /**
     * Card + orders read tier: view_tiktok is the card gate,
     * view_tiktok_orders is what visibleOrderTabs() checks per tab.
     */
    private function tiktokOperator(): User
    {
        $this->registerTiktok();

        $group = UserGroup::create(['name' => 'Fulfilment Hub TikTok Operators']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok', 'view_tiktok_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * manage_tiktok_orders implies view_tiktok_orders through the same
     * manage-implies-view cascade the other two channels' managers rely on.
     */
    private function tiktokManager(): User
    {
        $this->registerTiktok();

        $group = UserGroup::create(['name' => 'Fulfilment Hub TikTok Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok', 'manage_tiktok_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * shopeeOperator() with TikTok registered but never granted, so the
     * TikTok tab genuinely exists in the registry and is simply absent from
     * this user's own visibleOrderTabs() list. Same rationale as
     * shopeeOperatorWithLazadaRegistered(): without the registration the
     * membership check would go unexercised.
     */
    private function shopeeOperatorWithTiktokRegistered(): User
    {
        $this->registerTiktok();

        return $this->shopeeOperator();
    }

    public function test_default_render_includes_a_tiktok_card_that_switches_in_place(): void
    {
        $user = $this->tiktokOperator();

        $response = $this->actingAs($user)->get('/integrations/orders');

        $response->assertOk();
        $response->assertSee(route('integrations.orders', ['channel' => 'tiktok']), false);
        // The card stops linking out to the seller center once the panel is
        // extracted, keyed off panelChannels rather than a per-channel
        // literal in the view.
        $response->assertDontSee('href="' . e(route('ext.tiktok.orders.index')) . '"', false);
    }

    public function test_selecting_tiktok_switches_the_panel_in_place(): void
    {
        $user = $this->tiktokOperator();

        $response = $this->actingAs($user)->get('/integrations/orders?channel=tiktok');

        $response->assertOk();
        $response->assertSee('id="tiktok-orders-page"', false);
        $response->assertDontSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
    }

    public function test_tiktok_manager_sees_the_command_bar_on_the_hub_panel(): void
    {
        $manager = $this->tiktokManager();

        $response = $this->actingAs($manager)->get('/integrations/orders?channel=tiktok');

        $response->assertOk();
        $response->assertSee('id="btnFetchOrders"', false);
        $response->assertSee('id="formFetchTiktokOrders"', false);
    }

    public function test_tiktok_viewer_does_not_see_the_command_bar_on_the_hub_panel(): void
    {
        $viewer = $this->tiktokOperator();

        $response = $this->actingAs($viewer)->get('/integrations/orders?channel=tiktok');

        $response->assertOk();
        $response->assertDontSee('id="btnFetchOrders"', false);
        $response->assertDontSee('id="formFetchTiktokOrders"', false);
    }

    /**
     * Unauthorised fallback, direction one: a user holding only Shopee
     * permissions cannot select TikTok's panel.
     */
    public function test_shopee_only_user_requesting_tiktok_falls_back_to_summary(): void
    {
        $user = $this->shopeeOperatorWithTiktokRegistered();

        $this->assertFalse($user->hasPermission('view_tiktok'));
        $this->assertFalse($user->hasPermission('view_tiktok_orders'));
        $this->assertTrue($user->hasPermission('view_shopee_orders'));

        $response = $this->actingAs($user)->get('/integrations/orders?channel=tiktok');

        $response->assertOk();
        $response->assertDontSee('id="tiktok-orders-page"', false);
        $response->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
    }

    /**
     * Unauthorised fallback, direction two: a TikTok-only user cannot select
     * Shopee's panel either. The pair is what proves the gate is genuinely
     * per-channel rather than a check that happens to block one direction.
     */
    public function test_tiktok_only_user_requesting_shopee_falls_back_to_summary(): void
    {
        $user = $this->tiktokOperator();

        $this->assertFalse($user->hasPermission('view_shopee'));
        $this->assertFalse($user->hasPermission('view_shopee_orders'));
        $this->assertTrue($user->hasPermission('view_tiktok_orders'));

        $response = $this->actingAs($user)->get('/integrations/orders?channel=shopee');

        $response->assertOk();
        $response->assertDontSee('id="shopee-orders-page"', false);
        // Summary, not the empty state: this user does see a tab (TikTok),
        // so the request must land on the aggregate view.
        $response->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
    }

    public function test_tiktok_panel_links_keep_hub_context(): void
    {
        $user = $this->tiktokOperator();

        $response = $this->actingAs($user)->get('/integrations/orders?channel=tiktok');

        $response->assertOk();
        // The filter form posts back to the hub's own route, not TikTok's
        // seller-center route.
        $response->assertSee(
            '<form method="GET" action="' . e(route('integrations.orders')) . '" class="x-filters" id="orders-filter">',
            false
        );
        // The hidden context input renders at all, which the seller center
        // (which passes no hidden params) can never prove for this panel.
        $response->assertSee('<input type="hidden" name="channel" value="tiktok">', false);
        // Segment links specifically: tab= only ever appears on the status
        // segment, so a URL carrying both tab= and channel=tiktok proves the
        // SEGMENT keeps context, not only the strip card and the hidden
        // input above.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*integrations\/orders\?[^"]*(?:tab=[^"]*channel=tiktok|channel=tiktok[^"]*tab=)[^"]*"/',
            $response->getContent()
        );
    }

    /**
     * The To Ship sub-steps are a second family of self-links, built through
     * the same closure but with their own except-list. A sub-step link that
     * dropped the channel would bounce the operator back to Summary
     * mid-workflow.
     */
    public function test_tiktok_sub_step_links_keep_hub_context(): void
    {
        $user = $this->tiktokOperator();

        $response = $this->actingAs($user)->get('/integrations/orders?channel=tiktok&tab=TO_SHIP');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            // Lookaheads rather than a fixed order: Arr::query emits the
            // request's own params first, so channel= lands ahead of the
            // pending_sub= this link adds, and asserting one sequence would
            // be asserting an encoding detail rather than the behaviour.
            '/href="[^"]*integrations\/orders\?(?=[^"]*pending_sub=to_handover)(?=[^"]*channel=tiktok)[^"]*"/',
            $response->getContent()
        );
    }

    // ------------------------------------------------------------------
    // Round 2, Task 2: Venta and OpenCart become the fourth and fifth
    // channels rendering in place, and the first two that do it PER STORE.
    // Their tab ids carry a store suffix ("venta:2"), so what is being
    // guarded here is not only "does the panel render" but "does THIS
    // store's panel render, with only this store's orders in it".
    //
    // Own fixtures again, because setUp() only registers Shopee.
    // ------------------------------------------------------------------

    /**
     * Both multi-store channels registered, with TWO enabled stores each.
     *
     * Two is the whole point: a one-store fixture would let a hub that
     * ignored the store suffix entirely, and always rendered the channel's
     * first store, pass every assertion below.
     */
    private function registerMultistore(): void
    {
        $manager = $this->app->make(ExtensionManager::class);

        foreach (['venta', 'opencart'] as $id) {
            $manager->install($id);
            $manager->enable($id);
        }

        $this->app->register(VentaExtension::class);
        $this->app->register(OpencartExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        VentaSetting::updateOrCreate(
            ['store_name' => 'Gear Depot'],
            ['base_url' => 'https://one.venta.test', 'api_token' => 'venta-token-one', 'enabled' => true]
        );
        VentaSetting::updateOrCreate(
            ['store_name' => 'Switch Bazaar'],
            ['base_url' => 'https://two.venta.test', 'api_token' => 'venta-token-two', 'enabled' => true]
        );

        OpenCartSetting::updateOrCreate(
            ['store_name' => 'Parts Yard'],
            ['base_url' => 'https://one.opencart.test', 'api_key' => 'oc-key-one', 'enabled' => true]
        );
        OpenCartSetting::updateOrCreate(
            ['store_name' => 'Cable Loft'],
            ['base_url' => 'https://two.opencart.test', 'api_key' => 'oc-key-two', 'enabled' => true]
        );
    }

    /**
     * The two seeded store ids for a channel, first and second, read back
     * off the rows.
     *
     * Never hardcoded. InnoDB does not reset AUTO_INCREMENT when
     * RefreshDatabase rolls a test back, so by the time a later test in the
     * same run seeds these rows they are ids 15 and 16, not 1 and 2, and a
     * literal would name nothing. This is the trap that made Task 1's
     * malformed-key assertions pass without ever reaching a store lookup.
     */
    private function storeIds(string $channel): array
    {
        $rows = $channel === 'venta'
            ? [VentaSetting::where('store_name', 'Gear Depot')->firstOrFail()->id,
               VentaSetting::where('store_name', 'Switch Bazaar')->firstOrFail()->id]
            : [OpenCartSetting::where('store_name', 'Parts Yard')->firstOrFail()->id,
               OpenCartSetting::where('store_name', 'Cable Loft')->firstOrFail()->id];

        return array_map('intval', $rows);
    }

    /** One Venta order in a named store, returned so its id can be asserted on. */
    private function ventaOrder(int $storeId, string $customer, string $status): VentaOrder
    {
        return VentaOrder::create([
            'venta_setting_id'   => $storeId,
            'venta_order_id'     => ($storeId * 100000) + random_int(1, 99999),
            'venta_order_number' => ($storeId * 100000) + random_int(1, 99999),
            'status'             => $status,
            'status_id'          => 1,
            'customer_name'      => $customer,
            'total'              => 1234.50,
            'payment_method'     => 'Cash on delivery',
            'tracking_number'    => 'TRK' . $storeId . random_int(1000, 9999),
            'order_created_at'   => now()->subDay(),
        ]);
    }

    /**
     * One core catalog order tagged to an OpenCart store. The legacy
     * OpenCart-derived `order` table has many NOT NULL columns with no
     * default and MySQL's STRICT_TRANS_TABLES rejects an insert that omits
     * them; the blanks below exist only to satisfy the schema. Same approach
     * as SetOrderCurrencyRateTest::makeOrder().
     */
    private function opencartOrder(int $storeId, string $firstname, string $marketplaceOrderId): int
    {
        $prefix = (string) config('catalog.prefix');

        return (int) DB::table($prefix . 'order')->insertGetId([
            'firstname' => $firstname, 'lastname' => 'Buyer',
            'email' => strtolower($firstname) . '@example.test',
            'marketplace_source' => 'opencart:' . $storeId,
            'marketplace_order_id' => $marketplaceOrderId,
            'order_status_id' => 1, 'total' => 999.00,
            'currency_code' => 'PHP', 'currency_value' => 1,
            'date_added' => '2026-08-01 00:00:00', 'date_modified' => '2026-08-01 00:00:00',
            'invoice_prefix' => 'INV-', 'store_name' => '', 'store_url' => '',
            'telephone' => '', 'fax' => '', 'custom_field' => '',
            'payment_firstname' => '', 'payment_lastname' => '', 'payment_company' => '',
            'payment_address_1' => '', 'payment_address_2' => '', 'payment_city' => '',
            'payment_postcode' => '', 'payment_country' => '', 'payment_country_id' => 0,
            'payment_zone' => '', 'payment_zone_id' => 0,
            'payment_address_format' => '', 'payment_custom_field' => '',
            'payment_method' => '', 'payment_code' => '',
            'shipping_firstname' => '', 'shipping_lastname' => '', 'shipping_company' => '',
            'shipping_address_1' => '', 'shipping_address_2' => '', 'shipping_city' => '',
            'shipping_postcode' => '', 'shipping_country' => '', 'shipping_country_id' => 0,
            'shipping_zone' => '', 'shipping_zone_id' => 0,
            'shipping_address_format' => '', 'shipping_custom_field' => '',
            'shipping_method' => '', 'shipping_code' => '',
            'comment' => '', 'affiliate_id' => 0, 'commission' => 0,
            'marketing_id' => 0, 'tracking' => '', 'language_id' => 0, 'currency_id' => 0,
            'ip' => '', 'forwarded_ip' => '', 'user_agent' => '', 'accept_language' => '',
            'courier_id' => 0, 'tracking_number' => '', 'oe_import' => 0,
        ]);
    }

    /**
     * Card + orders read tier for a multi-store channel, plus the CORE
     * orders read tier.
     *
     * manage_orders is here because both of these panels link out to sales
     * orders (Venta's band shows the order the sync created; OpenCart's rows
     * ARE core orders) and both now gate that link on the permission the
     * core route actually checks, rather than offering a View that 403s.
     * Without it the number renders as plain text and an assertion about
     * which store's orders are listed would be asserting the gate instead.
     *
     * manage_orders and not view_orders: /sales/orders/{id} is gated
     * perm:view_orders|manage_orders, but only manage_orders exists as a row
     * in the permissions table, so it is the one a real operator can
     * actually hold. The views read both keys so a seeded view_orders would
     * work too.
     */
    private function multistoreOperator(string $channel): User
    {
        $this->registerMultistore();

        $group = UserGroup::create(['name' => 'Fulfilment Hub ' . $channel . ' Operators']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_' . $channel, 'view_' . $channel . '_orders', 'manage_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** Manage tier, which is what renders Venta's fetch command bar. */
    private function ventaManager(): User
    {
        $this->registerMultistore();

        $group = UserGroup::create(['name' => 'Fulfilment Hub Venta Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_venta', 'manage_venta_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * shopeeOperator() with both multi-store channels registered but never
     * granted, so their tabs genuinely exist in the registry and are simply
     * absent from this user's own visibleOrderTabs() list. Same rationale as
     * shopeeOperatorWithLazadaRegistered().
     */
    private function shopeeOperatorWithMultistoreRegistered(): User
    {
        $this->registerMultistore();

        return $this->shopeeOperator();
    }

    /**
     * The panel region of a hub render: everything inside <main> from the
     * panel's own root id onwards.
     *
     * Two things have to be excluded before an isolation claim means
     * anything on this page, and neither is a defect:
     *
     *   - the card strip, which sits above the panel and legitimately lists
     *     EVERY store, because it is the control that switches between them;
     *   - the command palette, which carries the app-wide jump list as a
     *     JSON blob with escaped slashes outside <main> entirely, and which
     *     would make a whole-document URL assertion pass while testing
     *     nothing.
     *
     * Slicing from the panel id forward drops the first; slicing <main> out
     * of the document drops the second.
     */
    private function hubPanel(string $html, string $pageId): string
    {
        $this->assertSame(1, preg_match('/<main class="x-content[^"]*">(.*?)<\/main>/s', $html, $main),
            'No content region rendered.');

        $at = strpos($main[1], 'id="' . $pageId . '"');
        $this->assertNotFalse($at, 'No ' . $pageId . ' panel rendered.');

        return substr($main[1], $at);
    }

    /** The card strip alone, for the mirror of every hubPanel() assertion. */
    private function hubStrip(string $html): string
    {
        $this->assertSame(1, preg_match('/<nav class="x-fh-strip"[^>]*>(.*?)<\/nav>/s', $html, $strip),
            'No card strip rendered.');

        return $strip[1];
    }

    public function test_default_render_gives_every_store_its_own_card_that_switches_in_place(): void
    {
        $user = $this->multistoreOperator('venta');

        $response = $this->actingAs($user)->get('/integrations/orders');

        $response->assertOk();

        foreach ($this->storeIds('venta') as $storeId) {
            // The card carries the FULL key, so it opens that store's panel
            // and not the channel's first store.
            $response->assertSee(e(route('integrations.orders', ['channel' => 'venta:' . $storeId])), false);
            // And no longer links out to that store's seller center.
            $response->assertDontSee(
                'href="' . e(route('ext.venta.orders.index', ['store' => $storeId])) . '"',
                false
            );
        }
    }

    public function test_selecting_one_venta_store_switches_that_stores_panel_in_place(): void
    {
        $user = $this->multistoreOperator('venta');
        [$first] = $this->storeIds('venta');

        $response = $this->actingAs($user)->get('/integrations/orders?channel=venta:' . $first);

        $response->assertOk();
        $response->assertSee('id="venta-orders-page"', false);
        $response->assertDontSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
        // The panel names the store it is showing, so an operator working
        // two shops is never guessing which one a control acts on.
        $response->assertSee('<span class="x-fh-panel__scope-value">Gear Depot</span>', false);
    }

    public function test_selecting_one_opencart_store_switches_that_stores_panel_in_place(): void
    {
        $user = $this->multistoreOperator('opencart');
        [, $second] = $this->storeIds('opencart');

        $response = $this->actingAs($user)->get('/integrations/orders?channel=opencart:' . $second);

        $response->assertOk();
        $response->assertSee('id="opencart-orders-page"', false);
        $response->assertDontSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
        $response->assertSee('<span class="x-fh-panel__scope-value">Cable Loft</span>', false);
    }

    /**
     * The isolation rule on the hub, Venta: standing in one store, the
     * other store's orders are not in the panel.
     *
     * Both stores are seeded with orders, and the assertion is pinned to the
     * live order ids read back off the rows rather than to guessed ones.
     */
    public function test_a_venta_stores_hub_panel_holds_none_of_the_other_stores_orders(): void
    {
        $user = $this->multistoreOperator('venta');
        [$first, $second] = $this->storeIds('venta');

        $mine = $this->ventaOrder($first, 'Depot Buyer', 'Pending');
        $theirs = $this->ventaOrder($second, 'Bazaar Buyer', 'Pending');

        $response = $this->actingAs($user)->get('/integrations/orders?channel=venta:' . $first);

        $response->assertOk();

        $panel = $this->hubPanel($response->getContent(), 'venta-orders-page');

        // Guards the premise: this IS the first store's panel, and it is not
        // empty, so the absence below is scoping and not an empty page.
        $this->assertStringContainsString('Depot Buyer', $panel);
        $this->assertStringContainsString((string) $mine->venta_order_number, $panel);

        $this->assertStringNotContainsString('Bazaar Buyer', $panel);
        $this->assertStringNotContainsString((string) $theirs->venta_order_number, $panel);
        // And no control in the panel points at the sibling store.
        $this->assertStringNotContainsString(
            route('ext.venta.orders.fetch', ['store' => $second]),
            $panel
        );
    }

    /** The same rule for OpenCart, whose rows are core catalog orders. */
    public function test_an_opencart_stores_hub_panel_holds_none_of_the_other_stores_orders(): void
    {
        $user = $this->multistoreOperator('opencart');
        [$first, $second] = $this->storeIds('opencart');

        $mineId = $this->opencartOrder($first, 'Yardley', 'OC-YARD-1');
        $theirsId = $this->opencartOrder($second, 'Loftus', 'OC-LOFT-1');

        $response = $this->actingAs($user)->get('/integrations/orders?channel=opencart:' . $first);

        $response->assertOk();

        $panel = $this->hubPanel($response->getContent(), 'opencart-orders-page');

        $this->assertStringContainsString('Yardley', $panel);
        $this->assertStringContainsString('OC-YARD-1', $panel);

        $this->assertStringNotContainsString('Loftus', $panel);
        $this->assertStringNotContainsString('OC-LOFT-1', $panel);
        // Pinned to the live ids, which is what proves the panel filtered on
        // marketplace_source rather than simply listing fewer rows. Quoted
        // as a full href, because /sales/orders/1 is a prefix of
        // /sales/orders/12 and a bare substring test on the sibling's URL
        // could pass or fail on nothing but which ids the run happened to
        // allocate.
        $this->assertStringContainsString('href="' . e(route('orders.show', $mineId)) . '"', $panel);
        $this->assertStringNotContainsString('href="' . e(route('orders.show', $theirsId)) . '"', $panel);
    }

    /**
     * The mirror of the isolation tests: the sibling store IS reachable, from
     * the card strip, which is the control that switches between them. Without
     * this, hubPanel()'s exclusion of the strip could be hiding a hub that had
     * stopped offering the other store at all.
     */
    public function test_the_card_strip_still_offers_the_sibling_store(): void
    {
        $user = $this->multistoreOperator('venta');
        [$first, $second] = $this->storeIds('venta');

        $response = $this->actingAs($user)->get('/integrations/orders?channel=venta:' . $first);

        $strip = $this->hubStrip($response->getContent());

        $this->assertStringContainsString(
            e(route('integrations.orders', ['channel' => 'venta:' . $second])),
            $strip
        );
        $this->assertStringContainsString('Switch Bazaar', $strip);
    }

    /**
     * Every family of self-link inside the panel carries the FULL store key,
     * not just the channel. A link that dropped the ":2" would silently move
     * the operator to the other shop mid-task, which is the worst failure
     * this page can have.
     */
    public function test_venta_panel_links_keep_the_full_store_key(): void
    {
        $user = $this->multistoreOperator('venta');
        [, $second] = $this->storeIds('venta');

        $this->ventaOrder($second, 'Bazaar Buyer', 'Pending');

        $response = $this->actingAs($user)->get('/integrations/orders?channel=venta:' . $second);

        $response->assertOk();

        // The filter form posts back to the hub, not to the seller center.
        $response->assertSee(
            '<form method="GET" action="' . e(route('integrations.orders')) . '" class="x-filters" id="orders-filter">',
            false
        );
        // The hidden context input carries the full key, which the seller
        // center (which passes no hidden params) can never prove.
        $response->assertSee('<input type="hidden" name="channel" value="venta:' . $second . '">', false);

        $panel = $this->hubPanel($response->getContent(), 'venta-orders-page');

        // Segment links: status= only ever appears on the status segment, so
        // a URL carrying both status= and the full key proves the SEGMENT
        // keeps context rather than only the strip card and the hidden input.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*integrations\/orders\?(?=[^"]*status=)(?=[^"]*channel=venta%3A' . $second . ')[^"]*"/',
            $panel,
            'A status segment link dropped the store key.'
        );
        // Sort links are a second family, built through the same closure with
        // its own except-list.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*integrations\/orders\?(?=[^"]*sort=total)(?=[^"]*channel=venta%3A' . $second . ')[^"]*"/',
            $panel,
            'A column sort link dropped the store key.'
        );
    }

    /**
     * Pagination is the third family, and the one built by Laravel rather
     * than by the panel, so it is worth its own assertion. Fifty-one orders
     * because the list pages at fifty.
     */
    public function test_venta_pagination_keeps_the_full_store_key(): void
    {
        $user = $this->multistoreOperator('venta');
        [$first] = $this->storeIds('venta');

        for ($i = 0; $i < 51; $i++) {
            $this->ventaOrder($first, 'Depot Buyer ' . $i, 'Pending');
        }

        $response = $this->actingAs($user)->get('/integrations/orders?channel=venta:' . $first);

        $response->assertOk();

        $panel = $this->hubPanel($response->getContent(), 'venta-orders-page');

        $this->assertMatchesRegularExpression(
            '/href="[^"]*integrations\/orders\?(?=[^"]*page=2)(?=[^"]*channel=venta%3A' . $first . ')[^"]*"/',
            $panel,
            'The next-page link dropped the store key.'
        );
    }

    public function test_venta_manager_sees_the_command_bar_on_the_hub_panel(): void
    {
        $manager = $this->ventaManager();
        [$first] = $this->storeIds('venta');

        $response = $this->actingAs($manager)->get('/integrations/orders?channel=venta:' . $first);

        $response->assertOk();
        $response->assertSee('id="formFetchVentaOrders"', false);
        // The fetch posts to THIS store's own route.
        $response->assertSee(e(route('ext.venta.orders.fetch', ['store' => $first])), false);
    }

    public function test_venta_viewer_does_not_see_the_command_bar_on_the_hub_panel(): void
    {
        $viewer = $this->multistoreOperator('venta');
        [$first] = $this->storeIds('venta');

        $response = $this->actingAs($viewer)->get('/integrations/orders?channel=venta:' . $first);

        $response->assertOk();
        $response->assertDontSee('id="formFetchVentaOrders"', false);
        $response->assertDontSee('id="btnFetchVentaOrders"', false);
    }

    /**
     * OpenCart's panel has no command bar for anyone, at any tier, because
     * the channel declares no order write route for one to submit to. This
     * is the honest statement of what exists, not an oversight.
     */
    public function test_the_opencart_panel_offers_no_command_bar_at_any_tier(): void
    {
        $this->registerMultistore();

        $group = UserGroup::create(['name' => 'Fulfilment Hub OpenCart Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_opencart', 'manage_opencart_orders', 'manage_orders'])
                ->pluck('id')
                ->all()
        );
        $user = User::factory()->create(['user_group_id' => $group->id]);

        [$first] = $this->storeIds('opencart');
        $orderId = $this->opencartOrder($first, 'Yardley', 'OC-YARD-1');

        $response = $this->actingAs($user)->get('/integrations/orders?channel=opencart:' . $first);

        $response->assertOk();

        $panel = $this->hubPanel($response->getContent(), 'opencart-orders-page');

        $this->assertStringNotContainsString('x-cmdbar', $panel);

        // Guards the premise: this user IS the manage tier, so the absence
        // above is the missing route and not the missing permission. What
        // the tier does unlock here is the row menu, built from core order
        // routes because that is where this channel's only writes live.
        $this->assertStringContainsString('href="' . e(route('orders.edit', $orderId)) . '"', $panel);
    }

    /**
     * Unauthorised fallback, direction one: a Shopee-only user cannot select
     * either multi-store channel's panel, for any store.
     */
    public function test_shopee_only_user_requesting_a_venta_store_falls_back_to_summary(): void
    {
        $user = $this->shopeeOperatorWithMultistoreRegistered();
        [$first] = $this->storeIds('venta');
        [$ocFirst] = $this->storeIds('opencart');

        $this->assertFalse($user->hasPermission('view_venta_orders'));
        $this->assertFalse($user->hasPermission('view_opencart_orders'));
        $this->assertTrue($user->hasPermission('view_shopee_orders'));

        $venta = $this->actingAs($user)->get('/integrations/orders?channel=venta:' . $first);
        $venta->assertOk();
        $venta->assertDontSee('id="venta-orders-page"', false);
        $venta->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);

        $opencart = $this->actingAs($user)->get('/integrations/orders?channel=opencart:' . $ocFirst);
        $opencart->assertOk();
        $opencart->assertDontSee('id="opencart-orders-page"', false);
        $opencart->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
    }

    /**
     * Unauthorised fallback, direction two: a Venta-only user cannot select
     * Shopee's panel, nor OpenCart's. The pair is what proves the gate is
     * per channel rather than a check that happens to block one direction.
     */
    public function test_venta_only_user_requesting_another_channel_falls_back_to_summary(): void
    {
        $user = $this->multistoreOperator('venta');
        [$ocFirst] = $this->storeIds('opencart');

        $this->assertFalse($user->hasPermission('view_shopee_orders'));
        $this->assertFalse($user->hasPermission('view_opencart_orders'));
        $this->assertTrue($user->hasPermission('view_venta_orders'));

        $shopee = $this->actingAs($user)->get('/integrations/orders?channel=shopee');
        $shopee->assertOk();
        $shopee->assertDontSee('id="shopee-orders-page"', false);
        $shopee->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);

        $opencart = $this->actingAs($user)->get('/integrations/orders?channel=opencart:' . $ocFirst);
        $opencart->assertOk();
        $opencart->assertDontSee('id="opencart-orders-page"', false);
        $opencart->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
    }

    /**
     * THERE IS NO PER-STORE PERMISSION MODEL IN THIS APP.
     *
     * view_venta_orders grants every Venta store, on the hub and everywhere
     * else: permissions are channel-level, and no store-level gate exists to
     * assert. This test says so out loud and pins the behaviour, so that if
     * a per-store model is ever introduced this is the assertion that fails
     * and forces the decision to be made deliberately rather than
     * discovered. It is deliberately NOT written as though a store gate
     * existed and happened to be open.
     */
    public function test_one_orders_permission_reaches_every_store_of_that_channel(): void
    {
        $user = $this->multistoreOperator('venta');
        [$first, $second] = $this->storeIds('venta');

        foreach ([[$first, 'Gear Depot'], [$second, 'Switch Bazaar']] as [$storeId, $label]) {
            $response = $this->actingAs($user)->get('/integrations/orders?channel=venta:' . $storeId);

            $response->assertOk();
            $response->assertSee('id="venta-orders-page"', false);
            $response->assertSee('<span class="x-fh-panel__scope-value">' . $label . '</span>', false);
        }
    }

    /**
     * Keys that name no store fall back to Summary rather than resolving to
     * some other store's panel. Built from the live ids for the same reason
     * the workspace suite builds its own: a literal goes stale.
     */
    public function test_a_channel_key_that_names_no_store_falls_back_to_summary(): void
    {
        $this->registerMultistore();

        $group = UserGroup::create(['name' => 'Fulfilment Hub Multistore Broad']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_venta', 'view_venta_orders', 'view_opencart', 'view_opencart_orders', 'view_shopee', 'view_shopee_orders'])
                ->pluck('id')
                ->all()
        );
        $user = User::factory()->create(['user_group_id' => $group->id]);

        [$first] = $this->storeIds('venta');

        // Guards the premise: a real key DOES render for this user, so every
        // fallback below is about the key and not about the permission.
        $this->actingAs($user)->get('/integrations/orders?channel=venta:' . $first)
            ->assertSee('id="venta-orders-page"', false);

        $keys = [
            'a bare multi-store channel' => 'venta',
            'a bare multi-store channel (opencart)' => 'opencart',
            'an unknown store' => 'venta:' . ($first + 9000),
            'an empty store' => 'venta:',
            'a non numeric store' => 'venta:abc',
            'a second suffix after a real store' => 'venta:' . $first . ':3',
            'a real store with trailing text' => 'venta:' . $first . 'x',
            'store zero' => 'venta:0',
            'a negative store' => 'venta:-' . $first,
            // A store suffix on a single-store channel names nothing.
            'a suffix on a single-store channel' => 'shopee:' . $first,
        ];

        foreach ($keys as $name => $key) {
            $response = $this->actingAs($user)->get('/integrations/orders?channel=' . urlencode($key));

            $response->assertOk();
            $response->assertDontSee('id="venta-orders-page"', false);
            $response->assertDontSee('id="opencart-orders-page"', false);
            $response->assertDontSee('id="shopee-orders-page"', false);
            $response->assertSee('<h2 class="x-fh-sec__title">Recent orders</h2>', false);
        }
    }
}
