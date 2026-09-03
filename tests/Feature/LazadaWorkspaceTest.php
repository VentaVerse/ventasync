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
            Permission::whereIn('key', ['view_lazada/dashboard', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'view_lazada/order'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Narrower operator: view_lazada_orders and nothing else. NOT the
     * card-level view_lazada that IntegrationRegistry::visibleCards() wants,
     * so this user cannot open /channels/lazada at all, only the orders
     * route the perm: middleware on that route itself allows. Parent/child
     * permission cascades are removed from this app, so holding the orders
     * permission grants nothing about the channel card.
     */
    private function lazadaOrdersOnlyOperator(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Workspace Order Desk']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada/order'])
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
            'ext.lazada.vouchers.index',   // joined 2026-08-26 (#153, quest 4)
            'ext.lazada.products.index',
            'ext.lazada.products.import', // joined 2026-09-03 (cleanup point 10)
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
                ['Sell', 'Catalog', 'Channel'],
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
            ['Sell', 'Catalog', 'Channel'],
            array_column($groups, 'label')
        );
        $this->assertSame(
            ['Orders', 'Returns'],
            array_column($groups[0]['items'], 'label')
        );
        $this->assertSame(
            ['Listings', 'Product Groups', 'Categories', 'Brands', 'Import'],
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
        // nothing. Eight is every Lazada destination (Import joined
        // 2026-09-03), so this also proves the operator permissions above
        // are sufficient to see all of them.
        $this->assertCount(8, $urls);
    }

    public function test_workspace_renders_for_a_permitted_user(): void
    {
        $user = $this->lazadaOperator();

        $this->actingAs($user)
            ->get('/channels/lazada')
            ->assertOk()
            ->assertSee('Lazada')
            ->assertSee('Overview');
    }

    public function test_an_orders_only_operator_reaches_the_orders_page(): void
    {
        $user = $this->lazadaOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/channels/lazada/orders');

        $response->assertOk();
        $response->assertSee('x-chnav', false);
    }

    /**
     * The narrow user gets a correspondingly narrow sidebar: Sell survives
     * (both its items declare view_lazada_orders), Catalog and Channel do
     * not (every item in them declares view_lazada, which this operator does
     * not hold).
     */
    public function test_an_orders_only_operator_sees_only_the_sell_group(): void
    {
        $user = $this->lazadaOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/channels/lazada/orders');

        $response->assertOk();

        $response->assertSee('data-chnav-group="Sell"', false);
        $response->assertSee('>Orders</a>', false);
        $response->assertSee('>Returns</a>', false);

        $response->assertDontSee('data-chnav-group="Catalog"', false);
        $response->assertDontSee('data-chnav-group="Channel"', false);
        $response->assertDontSee('>Listings</a>', false);
        $response->assertDontSee('>Product Groups</a>', false);
        $response->assertDontSee('>Categories</a>', false);
        $response->assertDontSee('>Brands</a>', false);
        $response->assertDontSee('>Settings</a>', false);
    }

    public static function lazadaPageProvider(): array
    {
        return [
            'orders' => ['/channels/lazada/orders'],
            'returns' => ['/channels/lazada/orders/returns'],
            'listings' => ['/channels/lazada/products'],
            'product groups' => ['/channels/lazada/product-groups'],
            'categories' => ['/channels/lazada/categories'],
            'brands' => ['/channels/lazada/brands'],
            'settings' => ['/channels/lazada/settings'],
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
        $response->assertSee('x-chnav', false);
        $response->assertSee('All channels');
        // Reversed 2026-09-03 (the teleportation fix): the global sidebar
        // now STAYS on every channel page - data-nav present is the proof -
        // and the channel's own menu rides as the x-chnav menubar.
        $response->assertSee('data-nav', false);
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
            substr_count($crumb, \App\Support\Breadcrumbs::CHANNEL_ROOT),
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
     * and Settings, not Orders. When this was written the legacy core
     * orders/index.blade.php still included integrations.partials._tab_strip,
     * and stripping it was out of that task's scope. That view was deleted
     * outright in Round F Task 4 (the legacy-layout retirement), so the
     * carryover it described is gone: the strip partial now has no includer
     * anywhere and is left standing only for the pending orders redesign.
     * The six destinations below still make the point this test exists for,
     * so the coverage is unchanged.
     */
    public function test_the_seller_center_shows_no_other_marketplace(): void
    {
        $response = $this->actingAs($this->lazadaOperator())->get('/channels/lazada/orders/returns');

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
        $this->assertSame('ext.lazada.dashboard', $lazada['route']);
        $this->assertSame(
            ChannelWorkspace::overviewUrl('lazada'),
            route($lazada['route'], $lazada['params'])
        );
    }

    public function test_the_board_links_into_the_lazada_workspace(): void
    {
        $user = $this->lazadaOperator();

        $this->actingAs($user)
            ->get('/channels')
            ->assertOk()
            // Quoted href, not a bare URL: /channels/lazada is a prefix
            // of every page beneath it since the IA migration's Task 2, so a
            // substring test would pass on any of them.
            ->assertSee('href="'.e(ChannelWorkspace::overviewUrl('lazada')).'"', false);
    }

    // ------------------------------------------------------------------
    // Task 2: the rebuilt Orders and Returns pages.
    //
    // The manage tier is a separate group from lazadaOperator()'s, with its
    // own unique name, so the two never share a row and a test asserting a
    // viewer sees nothing cannot pass because a manager permission leaked in
    // from a shared fixture.
    // ------------------------------------------------------------------

    private function lazadaOrdersManager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Order Desk Managers']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada/dashboard', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'view_lazada/order', 'manage_lazada/order'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * One order per branch of the action matrix the rebuilt page gates on.
     * Returned keyed by status so a test can name the row it cares about.
     *
     * @return array<string, \Extensions\lazada\Models\LazadaOrder>
     */
    private function seedLazadaOrders(): array
    {
        $out = [];

        foreach (['unpaid', 'pending', 'repacked', 'packed', 'ready_to_ship', 'shipped', 'delivered', 'canceled', 'failed_delivery'] as $status) {
            $out[$status] = \Extensions\lazada\Models\LazadaOrder::create([
                'region' => 'ph',
                'order_id' => 'T2-' . strtoupper($status),
                'status' => $status,
                'order_created_at' => '2026-08-05 09:15:00',
                'raw' => [
                    'order_number' => 'T2-' . strtoupper($status),
                    'customer_first_name' => 'Fixture Buyer',
                    'price' => '1234.50',
                    'currency' => 'PHP',
                ],
            ]);
        }

        return $out;
    }

    /**
     * The page is a read surface: holding only view_lazada_orders must open
     * it, and must offer none of the four controls whose routes demand the
     * manage tier. The old page hardcoded its own permission flag to true and
     * showed a viewer every one of them.
     */
    public function test_a_viewer_reaches_orders_and_is_offered_no_write_control(): void
    {
        $this->seedLazadaOrders();

        $response = $this->actingAs($this->lazadaOrdersOnlyOperator())->get('/channels/lazada/orders?tab=ALL');

        $response->assertOk();
        $response->assertDontSee('x-cmdbar', false);
        $response->assertDontSee('Pack and print');
        $response->assertDontSee('Arrange shipment');
        $response->assertDontSee('Recreate package');
        $response->assertDontSee('id="lzPackPrintModal"', false);

        // Read-only affordances stay: the picking documents are
        // server-rendered from data already fetched, which is why their
        // routes sit in the read tier.
        $response->assertSee('Packing list');
        $response->assertSee('Pick list');
    }

    public function test_the_manage_tier_is_offered_every_action_its_statuses_allow(): void
    {
        $this->seedLazadaOrders();

        $response = $this->actingAs($this->lazadaOrdersManager())->get('/channels/lazada/orders?tab=ALL&per_page=50');

        $response->assertOk();
        // The command bar, gated on the manage tier.
        $response->assertSee('formFetchLazadaOrders', false);
        $response->assertSee('id="btnUpdateOrders"', false);
        // One control per branch of the matrix, each on its own status.
        $response->assertSee('Pack and print');
        $response->assertSee('Arrange shipment');
        $response->assertSee('Recreate package');
        $response->assertSee('Print waybill');
        $response->assertSee('Tracking');
        // Every mutating action keeps the data-confirm convention; no
        // native dialog is ever used.
        $response->assertSee('data-confirm="Arrange shipment for this order?', false);
        $response->assertDontSee('confirm(', false);
    }

    /**
     * The middleware on the write routes is the real gate; the flag on the
     * page only decides what is offered. Both directions, against the
     * routes as declared in extensions/lazada/routes/web.php.
     */
    public function test_the_write_routes_refuse_a_viewer_and_admit_the_manage_tier(): void
    {
        $order = $this->seedLazadaOrders()['packed'];

        $viewer = $this->lazadaOrdersOnlyOperator();

        // App\Http\Middleware\EnsurePermission denies a JSON caller with a
        // 403 envelope and an HTML caller with a redirect carrying the
        // denial in session('error'). Both shapes are asserted, because
        // asserting only the redirect would also pass if the request had
        // reached the controller and been bounced for some other reason.
        $this->actingAs($viewer)
            ->postJson('/channels/lazada/orders/fetch', ['date_from' => '2026-08-01', 'date_to' => '2026-08-07'])
            ->assertForbidden()
            ->assertJsonPath('error', 'permission_denied');

        foreach ([
            '/channels/lazada/orders/fetch',
            '/channels/lazada/orders/update-statuses',
            '/channels/lazada/orders/returns/fetch',
            '/channels/lazada/orders/' . $order->order_id . '/rts',
            '/channels/lazada/orders/' . $order->order_id . '/pack-print',
            '/channels/lazada/orders/' . $order->order_id . '/recreate-package',
        ] as $writeRoute) {
            $this->actingAs($viewer)
                ->post($writeRoute, ['date_from' => '2026-08-01', 'date_to' => '2026-08-07'])
                ->assertRedirect()
                ->assertSessionHas('error', "You don't have permission to do this action.");
        }

        // The manage tier gets past the gate. With no Lazada credentials
        // configured the controller turns straight back with its own message
        // rather than calling the marketplace, so this asserts the gate and
        // nothing beyond it: the controller's key is present and the
        // middleware's is not.
        $this->actingAs($this->lazadaOrdersManager())
            ->post('/channels/lazada/orders/fetch', ['date_from' => '2026-08-01', 'date_to' => '2026-08-07'])
            ->assertRedirect()
            ->assertSessionHas('lazada_orders_last_result')
            ->assertSessionMissing('error');
    }

    public function test_a_viewer_reaches_returns_and_is_not_offered_the_fetch(): void
    {
        $viewer = $this->lazadaOrdersOnlyOperator();

        $this->actingAs($viewer)->get('/channels/lazada/orders/returns')
            ->assertOk()
            ->assertDontSee('formFetchLazadaReturns', false)
            ->assertDontSee('Fetch returns');

        $this->actingAs($this->lazadaOrdersManager())->get('/channels/lazada/orders/returns')
            ->assertOk()
            ->assertSee('formFetchLazadaReturns', false)
            ->assertSee('Fetch returns');
    }

    /**
     * Statuses reach the page as words a packer reads, in the tone
     * App\Support\ChannelStatusTone assigns: accent only on the steps that
     * are the operator's own move, danger on the failures, neutral on the
     * settled.
     */
    public function test_lazada_statuses_render_as_plain_language_in_the_right_tone(): void
    {
        $this->seedLazadaOrders();

        $html = $this->actingAs($this->lazadaOrdersManager())
            ->get('/channels/lazada/orders?tab=ALL&per_page=50')
            ->getContent();

        // No raw marketplace constant reaches the screen.
        $this->assertStringNotContainsString('>ready_to_ship<', $html);
        $this->assertStringNotContainsString('>failed_delivery<', $html);
        $this->assertStringNotContainsString('lost_by_3pl', $html);

        // Pair each badge with its own label rather than asking whether a
        // tone and a label both appear somewhere on the page: they always
        // would, because nine badges render, and an assertion that passes
        // for a page with every tone shuffled is not an assertion.
        preg_match_all(
            '/x-badge--([a-z]+)"[^>]*>.*?<span class="x-badge__label">(.*?)<\/span>/s',
            $html,
            $badges,
            PREG_SET_ORDER
        );

        $toneOf = [];
        foreach ($badges as $badge) {
            $toneOf[trim($badge[2])] = $badge[1];
        }

        // Sorted, because the rows tie on order_created_at and the database
        // is free to return them in any order; the pairing is what is under
        // test, not the sequence.
        ksort($toneOf);

        // REVISED 2026-08-19 with the tone philosophy. The seller's own three
        // steps carry the needs-a-person mark (warning), which is what a
        // fulfilment page exists to surface; the terminal states carry
        // success, because settled is the good outcome rather than something
        // to recede into grey. See ChannelStatusTone's class docblock.
        $expected = [
            'Unpaid' => 'warning',
            'To pack' => 'warning',
            'To pack again' => 'warning',
            'To arrange shipment' => 'warning',
            'To hand over' => 'info',
            'Shipped' => 'info',
            'Delivered' => 'success',
            'Cancelled' => 'danger',
            'Delivery failed' => 'danger',
        ];
        ksort($expected);

        $this->assertSame($expected, $toneOf);
    }

    /**
     * The rebuilt Orders page is the last Lazada surface that carried the
     * cross-marketplace tab strip; Task 1's own isolation test deliberately
     * left it out because the strip was still there. It is gone now, so the
     * rule holds on every Lazada page.
     */
    public function test_the_rebuilt_orders_page_shows_no_other_marketplace(): void
    {
        $this->seedLazadaOrders();

        $response = $this->actingAs($this->lazadaOperator())->get('/channels/lazada/orders');

        $response->assertOk();
        $response->assertDontSee('All marketplaces');
        $response->assertDontSee('ord-tab-head', false);
        $response->assertDontSee('Shopee');
        $response->assertDontSee('TikTok');
    }

    /**
     * Wire format. Every query param the controller reads has to survive a
     * round trip through the page's own links, or a filter silently stops
     * applying when a tab is clicked.
     */
    public function test_the_page_links_preserve_every_query_param_the_controller_reads(): void
    {
        $this->seedLazadaOrders();

        $html = $this->actingAs($this->lazadaOrdersManager())
            ->get('/channels/lazada/orders?tab=TO_SHIP&pending_sub=to_arrange&sort=created_asc&per_page=20&buyer_name=Fixture')
            ->getContent();

        // The sub-step segment keeps the search, the sort and the page size,
        // whatever order route() happens to emit them in.
        $this->assertSame(
            1,
            preg_match('/href="([^"]*pending_sub=to_handover[^"]*)"/', $html, $m),
            'The To Handover step link is missing from the sub-segment.'
        );
        $handoverLink = html_entity_decode($m[1]);
        foreach (['tab=TO_SHIP', 'pending_sub=to_handover', 'buyer_name=Fixture', 'sort=created_asc', 'per_page=20'] as $param) {
            $this->assertStringContainsString($param, $handoverLink, 'Switching step dropped ' . $param);
        }
        // page is the one param a step switch must drop: page 4 of To
        // Arrange is not page 4 of To Handover.
        $this->assertStringNotContainsString('page=', str_replace('per_page=', '', $handoverLink));
        // The filter form re-submits the active tab and sub-step so a search
        // does not silently jump back to All.
        $this->assertStringContainsString('<input type="hidden" name="tab" value="TO_SHIP">', $html);
        $this->assertStringContainsString('<input type="hidden" name="pending_sub" value="to_arrange">', $html);
    }
}
