<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\shopee\ShopeeExtension;
use Extensions\tiktok\TiktokExtension;
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
}
