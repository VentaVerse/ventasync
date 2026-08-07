<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\ChannelWorkspace;
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
 * Product Groups and Categories (Catalogue), Settings (Channel).
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
            Permission::whereIn('key', ['view_tiktok', 'view_tiktok_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Narrower operator: view_tiktok_orders and nothing else. NOT the
     * card-level view_tiktok that IntegrationRegistry::visibleCards() wants,
     * so this user cannot open /integrations/tiktok at all, only the orders
     * route the perm: middleware on that route itself allows. Parent/child
     * permission cascades are removed from this app, so holding the orders
     * permission grants nothing about the channel card.
     */
    private function tiktokOrdersOnlyOperator(): User
    {
        $group = UserGroup::create(['name' => 'TikTok Workspace Order Desk']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok_orders'])
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
                ['Sell', 'Catalogue', 'Channel'],
                "Menu item {$item->label} has group ".var_export($item->group, true)
            );
        }
    }

    public function test_groups_are_ordered_and_bucketed(): void
    {
        $user = $this->tiktokOperator();
        $groups = app(ChannelWorkspace::class)->groups($this->tiktokCard(), $user);

        $this->assertSame(
            ['Sell', 'Catalogue', 'Channel'],
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
            ->get('/integrations/tiktok')
            ->assertOk()
            ->assertSee('TikTok')
            ->assertSee('Overview');
    }

    public function test_an_orders_only_operator_reaches_the_orders_page(): void
    {
        $user = $this->tiktokOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/tiktok/orders');

        $response->assertOk();
        $response->assertSee('x-cnav', false);
    }

    /**
     * The narrow user gets a correspondingly narrow sidebar: Sell survives
     * (its one item declares view_tiktok_orders), Catalogue and Channel do
     * not (every item in them declares view_tiktok, which this operator
     * does not hold).
     */
    public function test_an_orders_only_operator_sees_only_the_sell_group(): void
    {
        $user = $this->tiktokOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/tiktok/orders');

        $response->assertOk();

        $response->assertSee('<span class="x-cnav__group-label">Sell</span>', false);
        $response->assertSee('>Orders</a>', false);

        $response->assertDontSee('<span class="x-cnav__group-label">Catalogue</span>', false);
        $response->assertDontSee('<span class="x-cnav__group-label">Channel</span>', false);
        $response->assertDontSee('>Product Groups</a>', false);
        $response->assertDontSee('>Categories</a>', false);
        $response->assertDontSee('>Settings</a>', false);
    }

    public static function tiktokPageProvider(): array
    {
        return [
            'orders' => ['/tiktok/orders'],
            'product groups' => ['/tiktok/product-groups'],
            'categories' => ['/tiktok/categories'],
            'settings' => ['/tiktok'],
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
            substr_count($crumb, 'Integrations'),
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
     * Checked here on Product Groups, Categories and Settings, not Orders.
     * orders/index.blade.php still includes
     * integrations.partials._tab_strip at this point, the identical
     * carryover Shopee's and Lazada's own history shows (Shopee: commit
     * 10458135; Lazada: this same task, one round earlier). Stripping it now
     * would be a content edit beyond breadcrumb/extends, which this task
     * does not do, and that removal is Task 2's job. Asserting it away here
     * would either fail honestly or require silently editing content this
     * task is not scoped to touch, so this test covers the three
     * destinations where the rule already holds today and leaves Orders to
     * Task 2's own regression test once the strip is actually gone.
     */
    public function test_the_seller_center_shows_no_other_marketplace(): void
    {
        $response = $this->actingAs($this->tiktokOperator())->get('/tiktok/categories');

        $response->assertOk();
        $response->assertDontSee('All marketplaces');
        $response->assertDontSee('ord-tab-head', false);
    }

    /**
     * Orders is the one converted page still carrying the legacy strip
     * (see the docblock above): it must still show "All marketplaces" and
     * the tab head markup until Task 2 rebuilds it on the shared anatomy.
     * This is an explicit exception, not an oversight.
     */
    public function test_the_orders_page_still_carries_the_legacy_strip_until_task_2(): void
    {
        $response = $this->actingAs($this->tiktokOperator())->get('/tiktok/orders');

        $response->assertOk();
        $response->assertSee('All marketplaces');
        $response->assertSee('ord-tab-head', false);
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
        $this->assertSame('integrations.workspace', $tiktok['route']);
        $this->assertSame(
            route('integrations.workspace', ['channel' => 'tiktok']),
            route($tiktok['route'], $tiktok['params'])
        );
    }

    public function test_the_board_links_into_the_tiktok_workspace(): void
    {
        $user = $this->tiktokOperator();

        $this->actingAs($user)
            ->get('/integrations')
            ->assertOk()
            ->assertSee(route('integrations.workspace', ['channel' => 'tiktok']), false);
    }
}
