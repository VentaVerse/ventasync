<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\ChannelWorkspace;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokOrderProduct;
use Extensions\tiktok\TiktokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 2, Task 1: TikTok gets what Shopee and Lazada already have. Modeled
 * directly on LazadaWorkspaceTest, own idempotent extension setup and own
 * uniquely-named user groups so this suite never collides with Shopee's or
 * Lazada's when all three run in the same process.
 *
 * TikTok has no returns page, no listings page and no brands page, so its
 * workspace is four destinations rather than Lazada's seven: Orders (Sell),
 * Product Groups and Categories (Catalog), Settings (Channel).
 */
class TiktokWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Holds both real TikTok read permissions and nothing else: a genuine
     * read-only operator, not an admin stand-in. view_tiktok is the card
     * gate; view_tiktok_orders is what the orders route demands
     * (perm:view_tiktok_orders|manage_tiktok_orders).
     */
    private function tiktokOperator(): User
    {
        $group = UserGroup::create(['name' => 'TikTok Workspace Operators']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok/dashboard', 'view_tiktok/settings', 'view_tiktok/product_group', 'view_tiktok/order'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Narrower operator: view_tiktok_orders and nothing else. NOT the
     * card-level view_tiktok that IntegrationRegistry::visibleCards() wants,
     * so this user cannot open /channels/tiktok at all, only the orders
     * route the perm: middleware on that route itself allows. Parent/child
     * permission cascades are removed from this app, so holding the orders
     * permission grants nothing about the channel card.
     */
    private function tiktokOrdersOnlyOperator(): User
    {
        $group = UserGroup::create(['name' => 'TikTok Workspace Order Desk']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok/order'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Same idempotent pattern LazadaWorkspaceTest::setUp() uses: install+
     * enable the extension row (Extension::updateOrCreate, so this is a
     * no-op if it already exists), then register the provider directly
     * because ExtensionServiceProvider::boot() has already run by the time
     * this method executes and only registers providers for rows that were
     * already enabled at that moment. $this->app->register() is idempotent
     * too (the container returns the already-registered instance rather than
     * double-booting), so this is safe whether TikTok was already live or
     * not.
     *
     * refreshNameLookups() is load-bearing: registering the provider here
     * loads all of TikTok's routes into the RouteCollection, but the
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
        $manager->install('tiktok');
        $manager->enable('tiktok');

        $this->app->register(TiktokExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function tiktokCard(): \App\Integrations\IntegrationCard
    {
        $registry = app(IntegrationRegistry::class);

        foreach ($registry->all() as $provider) {
            foreach ($provider->integrationCards() as $card) {
                if ($card->id === 'tiktok') {
                    return $card;
                }
            }
        }

        $this->fail('TikTok did not register an IntegrationCard.');
    }

    public function test_tiktok_menu_covers_every_workspace_destination(): void
    {
        $routes = array_map(
            fn ($i) => $i->routeName,
            $this->tiktokCard()->menu
        );

        $this->assertEqualsCanonicalizing([
            'ext.tiktok.orders.index',
            'ext.tiktok.product-groups.index',
            'ext.tiktok.categories.index',
            'ext.tiktok.index',
        ], $routes);
    }

    public function test_every_tiktok_menu_item_declares_a_known_group(): void
    {
        foreach ($this->tiktokCard()->menu as $item) {
            $this->assertContains(
                $item->group,
                ['Sell', 'Catalog', 'Channel'],
                "Menu item {$item->label} has group ".var_export($item->group, true)
            );
        }
    }

    public function test_groups_are_ordered_and_bucketed(): void
    {
        $user = $this->tiktokOperator();
        $groups = app(ChannelWorkspace::class)->groups($this->tiktokCard(), $user);

        $this->assertSame(
            ['Sell', 'Catalog', 'Channel'],
            array_column($groups, 'label')
        );
        $this->assertSame(
            ['Orders'],
            array_column($groups[0]['items'], 'label')
        );
        $this->assertSame(
            ['Product Groups', 'Categories'],
            array_column($groups[1]['items'], 'label')
        );
        $this->assertSame(
            ['Settings'],
            array_column($groups[2]['items'], 'label')
        );
    }

    public function test_every_destination_is_linkable(): void
    {
        $user = $this->tiktokOperator();
        $groups = app(ChannelWorkspace::class)->groups($this->tiktokCard(), $user);

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
        // nothing. Four is every TikTok destination, so this also proves the
        // operator permissions above are sufficient to see all of them.
        $this->assertCount(4, $urls);
    }

    public function test_workspace_renders_for_a_permitted_user(): void
    {
        $user = $this->tiktokOperator();

        $this->actingAs($user)
            ->get('/channels/tiktok')
            ->assertOk()
            ->assertSee('TikTok')
            ->assertSee('Overview');
    }

    public function test_an_orders_only_operator_reaches_the_orders_page(): void
    {
        $user = $this->tiktokOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/channels/tiktok/orders');

        $response->assertOk();
        $response->assertSee('x-cnav', false);
    }

    /**
     * The narrow user gets a correspondingly narrow sidebar: Sell survives
     * (its one item declares view_tiktok_orders), Catalog and Channel do
     * not (every item in them declares view_tiktok, which this operator
     * does not hold).
     */
    public function test_an_orders_only_operator_sees_only_the_sell_group(): void
    {
        $user = $this->tiktokOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/channels/tiktok/orders');

        $response->assertOk();

        $response->assertSee('<span class="x-cnav__group-label">Sell</span>', false);
        $response->assertSee('>Orders</a>', false);

        $response->assertDontSee('<span class="x-cnav__group-label">Catalog</span>', false);
        $response->assertDontSee('<span class="x-cnav__group-label">Channel</span>', false);
        $response->assertDontSee('>Product Groups</a>', false);
        $response->assertDontSee('>Categories</a>', false);
        $response->assertDontSee('>Settings</a>', false);
    }

    public static function tiktokPageProvider(): array
    {
        return [
            'orders' => ['/channels/tiktok/orders'],
            'product groups' => ['/channels/tiktok/product-groups'],
            'categories' => ['/channels/tiktok/categories'],
            'settings' => ['/channels/tiktok/settings'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tiktokPageProvider')]
    public function test_tiktok_pages_render_inside_the_workspace(string $path): void
    {
        // tiktokOperator() holds exactly view_tiktok and view_tiktok_orders,
        // which is every permission any of these four routes' perm:
        // middleware demands, so a real read-only operator proves all four
        // render inside the workspace.
        $user = $this->tiktokOperator();

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
     * The layout owns the "Integrations / TikTok Shop" trail, so each view
     * supplies only its leaf. Before this task the views carried the full
     * trail themselves, which would have read "Integrations / TikTok Shop /
     * Integrations / TikTok Shop / Orders" on every one of the four pages,
     * the exact bug Shopee's own conversion hit and fixed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tiktokPageProvider')]
    public function test_the_breadcrumb_trail_is_not_duplicated(string $path): void
    {
        $html = $this->actingAs($this->tiktokOperator())->get($path)->getContent();

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
            substr_count($crumb, 'TikTok'),
            'Breadcrumb repeats the channel on '.$path.': '.preg_replace('/\s+/', ' ', trim($crumb))
        );
    }

    /**
     * The cross-marketplace tab strip belongs to the unified Orders page.
     * Inside a seller center you see only that marketplace.
     *
     * Checked on Product Groups, Categories and Settings here, and on Orders
     * in its own test below. Task 1 left Orders carrying the strip because
     * removing it was a content edit beyond breadcrumb/extends; Task 2
     * rebuilt the page on the shared anatomy and dropped it, which is what
     * the Orders test now asserts instead of the temporary exception it
     * asserted before.
     */
    public function test_the_seller_center_shows_no_other_marketplace(): void
    {
        $response = $this->actingAs($this->tiktokOperator())->get('/channels/tiktok/categories');

        $response->assertOk();
        $response->assertDontSee('All marketplaces');
        $response->assertDontSee('ord-tab-head', false);
    }

    /**
     * Orders was the last TikTok page carrying the cross-marketplace strip.
     * Task 2's rebuild dropped it, so the rule now holds on all four
     * destinations rather than three.
     */
    public function test_the_orders_page_shows_no_other_marketplace(): void
    {
        $response = $this->actingAs($this->tiktokOperator())->get('/channels/tiktok/orders');

        $response->assertOk();
        $response->assertDontSee('All marketplaces');
        $response->assertDontSee('ord-tab-head', false);
        // The page it was replaced by, positively identified: without this
        // the two assertions above would also pass on a page that failed to
        // render its list at all.
        $response->assertSee('id="tiktok-orders-page"', false);
    }

    public function test_the_channels_group_offers_tiktok_and_lands_on_the_seller_center(): void
    {
        $this->actingAs($this->tiktokOperator());

        $channels = collect(\App\Support\Navigation::groups())
            ->firstWhere('label', 'Channels');

        $this->assertNotNull($channels, 'The nav has no Channels group.');

        $labels = array_column($channels['items'], 'label');
        $this->assertContains('All channels', $labels);
        $this->assertContains('TikTok Shop', $labels);

        $tiktok = collect($channels['items'])->firstWhere('label', 'TikTok Shop');
        $this->assertSame('ext.tiktok.dashboard', $tiktok['route']);
        $this->assertSame(
            ChannelWorkspace::overviewUrl('tiktok'),
            route($tiktok['route'], $tiktok['params'])
        );
    }

    public function test_the_board_links_into_the_tiktok_workspace(): void
    {
        $user = $this->tiktokOperator();

        $this->actingAs($user)
            ->get('/channels')
            ->assertOk()
            // Quoted href, not a bare URL: /channels/tiktok is a prefix
            // of every page beneath it since the IA migration's Task 2, so a
            // substring test would pass on any of them.
            ->assertSee('href="'.e(ChannelWorkspace::overviewUrl('tiktok')).'"', false);
    }

    // ------------------------------------------------------------------
    // Task 2: the orders page rebuilt on the shared `co-` anatomy. The
    // fixtures below seed one order per branch of the action matrix, so a
    // test that asserts a control renders is asserting it against a row
    // whose status actually reaches that branch.
    // ------------------------------------------------------------------

    /**
     * Manage tier for TikTok orders. manage_tiktok_orders implies
     * view_tiktok_orders through User::hasPermission()'s manage-implies-view
     * cascade, so this user reaches the page AND its write controls.
     */
    private function tiktokManager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok Workspace Managers']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok/dashboard', 'view_tiktok/settings', 'view_tiktok/product_group', 'manage_tiktok/order'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * One order per status the controller groups into a tab, each with a
     * single line item, so every branch of the action matrix is on screen at
     * once when the ALL tab renders. Returns the order ids keyed by status.
     *
     * @return array<string, int>
     */
    private function seedOrderPerStatus(): array
    {
        $ids = [];

        foreach ([
            'UNPAID',
            'AWAITING_SHIPMENT',
            'AWAITING_COLLECTION',
            'IN_TRANSIT',
            'DELIVERED',
            'COMPLETED',
            'CANCELLED',
        ] as $i => $status) {
            $order = TikTokOrder::create([
                'region' => 'PH',
                'order_id' => '57600000000000' . $i,
                'status' => $status,
                'order_created_at' => now()->subDays($i + 1),
                'buyer_name' => 'Buyer number ' . $i,
                'raw' => [
                    'payment' => ['total_amount' => '1250.00', 'currency' => 'PHP'],
                    'shipping_provider' => 'Flash Express',
                    'tracking_number' => 'TT' . $i . '99887766',
                    'tts_sla_time' => now()->addDay()->getTimestamp(),
                ],
            ]);

            TikTokOrderProduct::create([
                'tiktok_order_id' => $order->id,
                'order_line_item_id' => 'LI' . $i,
                'sku' => 'SKU-' . $i,
                'name' => 'Seeded product ' . $i,
                'variation' => 'Blue, Large',
                'quantity' => 2,
                'item_price' => 700.00,
                'sale_price' => 625.00,
                'status' => $status,
                'image' => '',
            ]);

            $ids[$status] = $order->id;
        }

        return $ids;
    }

    public function test_a_manager_sees_the_command_bar_and_the_ship_control(): void
    {
        $ids = $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokManager())->get('/channels/tiktok/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee('id="btnFetchOrders"', false);
        $response->assertSee('id="btnUpdateOrders"', false);

        // Ship posts to the write route for the one order that is awaiting
        // shipment, and keeps the confirmation prompt the legacy page used.
        $response->assertSee(
            'action="' . e(route('ext.tiktok.orders.ship', $ids['AWAITING_SHIPMENT'])) . '"',
            false
        );
        /* The confirm names what it does to the marketplace. "Ship this
           order?" asked nothing checkable - every sibling confirm on this
           surface states the consequence, and this one is a push TikTok
           cannot take back. */
        $response->assertSee('data-confirm="Mark this order shipped on TikTok Shop?', false);
        $response->assertSee('cannot be undone from here', false);
        $response->assertDontSee('data-confirm="Ship this order?"', false);
    }

    /**
     * The mutation this guards: dropping the $canManageTiktokOrders check (or
     * hardcoding it back to true, which is exactly what the pre-Task-2 page
     * did) hands a read-only operator a command bar and a Ship button that
     * both 403 on submit.
     */
    public function test_a_viewer_sees_neither_the_command_bar_nor_the_ship_control(): void
    {
        $ids = $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokOrdersOnlyOperator())->get('/channels/tiktok/orders?tab=ALL');

        $response->assertOk();
        // Guards the premise: the awaiting-shipment row IS on this page, so
        // the absence below is the permission gate and not an empty list.
        $response->assertSee('To pack');

        $response->assertDontSee('id="btnFetchOrders"', false);
        $response->assertDontSee('id="btnUpdateOrders"', false);
        $response->assertDontSee(
            'action="' . e(route('ext.tiktok.orders.ship', $ids['AWAITING_SHIPMENT'])) . '"',
            false
        );
        $response->assertDontSee('data-confirm="Ship this order?"', false);
    }

    /**
     * The read tier of extensions/tiktok/routes/web.php covers the waybill
     * and the tracking lookup, so a viewer keeps both on the statuses that
     * offer them. A page that hid every action from a viewer would be
     * consistent but wrong: it would take away two controls the routes
     * themselves allow.
     */
    public function test_a_viewer_keeps_the_waybill_and_tracking_controls(): void
    {
        $ids = $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokOrdersOnlyOperator())->get('/channels/tiktok/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee(e(route('ext.tiktok.orders.awb', $ids['AWAITING_COLLECTION'])), false);
        $response->assertSee(e(route('ext.tiktok.orders.tracking', $ids['IN_TRANSIT'])), false);
        // Refresh is offered on handover only, where a label gets reprinted.
        $response->assertSee(e(route('ext.tiktok.orders.awb', [$ids['AWAITING_COLLECTION'], 'refresh' => 1])), false);
        $response->assertDontSee(e(route('ext.tiktok.orders.awb', [$ids['IN_TRANSIT'], 'refresh' => 1])), false);
    }

    /**
     * Unpaid and cancelled orders have nothing a seller can act on, so their
     * actions cell carries no control at all rather than a disabled one.
     */
    public function test_unpaid_and_cancelled_orders_offer_no_actions(): void
    {
        $ids = $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokManager())->get('/channels/tiktok/orders?tab=ALL');

        $response->assertOk();
        foreach (['UNPAID', 'CANCELLED'] as $status) {
            $response->assertDontSee(e(route('ext.tiktok.orders.awb', $ids[$status])), false);
            $response->assertDontSee(e(route('ext.tiktok.orders.tracking', $ids[$status])), false);
            $response->assertDontSee(e(route('ext.tiktok.orders.ship', $ids[$status])), false);
        }
    }

    /**
     * Every status the panel's own tab map groups on has a tone and a label
     * in App\Support\ChannelStatusTone.
     *
     * REVISED 2026-08-19 with the tone philosophy itself. The mark that says
     * "a person has to do something" is the warning tone, not success, and
     * it is spent on exactly one status here: the only one with a
     * marketplace write behind it. Success now means settled and settled
     * well, so the two terminal states carry it instead of receding into
     * neutral. See the class docblock on ChannelStatusTone for why four maps
     * moved: DESIGN.md defines --bl-good as settled and --bl-attn as needs a
     * person, and this map used to say the opposite of both.
     */
    public function test_every_real_status_pairs_a_tone_with_a_plain_label(): void
    {
        $expected = [
            'UNPAID'              => ['warning', 'Unpaid'],
            'AWAITING_SHIPMENT'   => ['warning', 'To pack'],
            'AWAITING_COLLECTION' => ['info', 'To hand over'],
            'IN_TRANSIT'          => ['info', 'Shipped'],
            'DELIVERED'           => ['success', 'Delivered'],
            'COMPLETED'           => ['success', 'Completed'],
            'CANCELLED'           => ['danger', 'Cancelled'],
        ];

        foreach ($expected as $status => [$tone, $label]) {
            $this->assertSame(
                $tone,
                \App\Support\ChannelStatusTone::toneFor('tiktok.orders', $status),
                'Wrong tone for ' . $status
            );
            $this->assertSame(
                $label,
                \App\Support\ChannelStatusTone::labelFor('tiktok.orders', $status),
                'Wrong label for ' . $status
            );
            // No marketplace constant reaches a screen raw.
            $this->assertNotSame($status, \App\Support\ChannelStatusTone::labelFor('tiktok.orders', $status));
        }

        // Read back off the class itself, not off $expected: the loop above
        // already proved the two agree, so this is the statement about how
        // the tones partition this channel's statuses.
        //
        // REVISED 2026-08-19. This used to assert the success tone was spent
        // exactly once, on AWAITING_SHIPMENT, because success was being used
        // as an "act now" accent. That was the inversion this revision
        // removed: DESIGN.md gives four state colours and none of them means
        // "the operator's move" separately from "needs a person", so unpaid
        // and to-pack legitimately share one mark and the label tells them
        // apart. What is worth pinning is that the two groups never overlap:
        // nothing that still needs a person may read as settled.
        $needsAPerson = [];
        $settled = [];
        foreach (array_keys($expected) as $status) {
            $tone = \App\Support\ChannelStatusTone::toneFor('tiktok.orders', $status);
            if ($tone === 'warning') {
                $needsAPerson[] = $status;
            }
            if ($tone === 'success') {
                $settled[] = $status;
            }
        }

        $this->assertSame(['UNPAID', 'AWAITING_SHIPMENT'], $needsAPerson);
        $this->assertSame(['DELIVERED', 'COMPLETED'], $settled);
    }

    /**
     * The rendered page shows those labels, not the raw constants. Asserting
     * this on the page as well as on the map is what proves the view is
     * actually going through ChannelStatusTone rather than printing
     * str_replace('_', ' ', $status) the way the old page did.
     */
    public function test_the_status_column_shows_plain_labels(): void
    {
        $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokManager())->get('/channels/tiktok/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee('To pack');
        $response->assertSee('To hand over');
        $response->assertDontSee('AWAITING_SHIPMENT');
        $response->assertDontSee('AWAITING SHIPMENT');
        $response->assertDontSee('AWAITING_COLLECTION');
    }

    /**
     * The To Ship sub-steps filter the list to one status each, which is what
     * makes the sub-segment worth having: standing on To Pack must not also
     * list the parcels already waiting for a courier.
     */
    public function test_the_to_ship_sub_steps_filter_to_one_status_each(): void
    {
        $this->seedOrderPerStatus();

        // Order numbers rather than buyer names: the seeded buyers are
        // deliberately anonymous so no fixture string can accidentally
        // satisfy an assertion about a raw status constant elsewhere.
        $packNumber = '576000000000001';
        $handoverNumber = '576000000000002';

        $manager = $this->tiktokManager();

        $toPack = $this->actingAs($manager)->get('/channels/tiktok/orders?tab=TO_SHIP&pending_sub=to_pack');
        $toPack->assertOk();
        $toPack->assertSee($packNumber);
        $toPack->assertDontSee($handoverNumber);

        $toHandover = $this->actingAs($manager)->get('/channels/tiktok/orders?tab=TO_SHIP&pending_sub=to_handover');
        $toHandover->assertOk();
        $toHandover->assertSee($handoverNumber);
        $toHandover->assertDontSee($packNumber);
    }

    /**
     * The order number opens the detail page in place. It used to open a new
     * tab as a reviewed stopgap, because the detail page was still legacy
     * chrome and a new tab was the only way to keep the seller center on
     * screen. That detail page now renders inside this same workspace (board
     * card #5, Task 3), so the stopgap is retired and the link navigates
     * here: the channel sidebar and the filtered list both survive.
     */
    public function test_the_order_number_opens_the_detail_page_in_place(): void
    {
        $ids = $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokManager())->get('/channels/tiktok/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee(
            '<a class="co-sn" href="' . e(route('ext.tiktok.orders.show', $ids['COMPLETED'])) . '">',
            false
        );
        $response->assertDontSee('target="_blank" rel="noopener">' . $ids['COMPLETED'], false);
    }
}
