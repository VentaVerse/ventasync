<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationRegistry;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\ChannelWorkspace;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\OpencartExtension;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\VentaExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 2, task 1 of the Venta + OpenCart conversion: the two multi-store
 * channels get a workspace PER STORE, keyed `venta:2` / `opencart:1`, the
 * same format StoreLink and OrderTab ids already use.
 *
 * What makes this different from the three single-store suites it is modeled
 * on (ChannelWorkspaceTest, LazadaWorkspaceTest, TiktokWorkspaceTest): every
 * destination these channels have is store-scoped, so there is no storeless
 * page to land on, and the thing most worth guarding is that one store's
 * workspace never shows the other store's links. Two stores per channel are
 * seeded for exactly that reason - a one-store fixture would let a workspace
 * that ignored the store entirely pass every assertion here.
 */
class MultistoreWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Both channels' extensions installed, enabled and registered, then two
     * stores seeded for each.
     *
     * Same idempotent pattern the other workspace suites use: install() is
     * updateOrCreate() and enable() is an update(), so both are no-ops when
     * the row already exists, and $this->app->register() returns the
     * already-registered provider rather than double-booting one.
     *
     * refreshNameLookups() is load-bearing. Registering a provider here loads
     * its routes into the RouteCollection, but the name-lookup table
     * Route::has()/getByName() read is only rebuilt on demand, and
     * ChannelWorkspace::allows() calls Route::has() as its first gate. Without
     * this every positive assertion below would fail and every negative one
     * would pass for the wrong reason.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);

        // Shopee comes along as the single-store control: without a real
        // converted single-store channel registered, "a store suffix on a
        // single-store channel resolves to nothing" would pass for the wrong
        // reason (no such card at all), and the "return shapes are unchanged
        // for single-store channels" claim would go unasserted here.
        $providers = [
            'venta' => VentaExtension::class,
            'opencart' => OpencartExtension::class,
            'shopee' => \Extensions\shopee\ShopeeExtension::class,
        ];

        foreach ($providers as $id => $class) {
            $manager->install($id);
            $manager->enable($id);
            $this->app->register($class);
        }

        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->seedStores();
    }

    /**
     * Two stores per channel, created by name so re-running this is a no-op
     * rather than a duplicate. Ids are never assumed anywhere in this suite:
     * every assertion reads the id back off the seeded row, because in the
     * real sandbox Venta's stores are ids 2 and 3, not 1 and 2.
     *
     * The states are deliberately different per store so a reading that
     * ignored the store would be visible: Gear Depot synced minutes ago
     * (connected), Switch Bazaar never synced (needs attention), Parts Yard
     * synced (connected), Cable Loft still disabled (not connected).
     *
     * The names deliberately do not contain the channel name. Real store
     * names are brands ("Shop A", "Shop B"), and a fixture named
     * "Venta Store Two" would make the breadcrumb assertion below count the
     * word Venta twice for a reason that has nothing to do with the trail.
     */
    private function seedStores(): void
    {
        VentaSetting::updateOrCreate(
            ['store_name' => 'Gear Depot'],
            ['base_url' => 'https://one.venta.test', 'api_token' => 'venta-token-one',
             'enabled' => true, 'last_order_sync_at' => now()->subMinutes(5)]
        );

        VentaSetting::updateOrCreate(
            ['store_name' => 'Switch Bazaar'],
            ['base_url' => 'https://two.venta.test', 'api_token' => 'venta-token-two',
             'enabled' => true, 'last_order_sync_at' => null]
        );

        OpenCartSetting::updateOrCreate(
            ['store_name' => 'Parts Yard'],
            ['base_url' => 'https://one.opencart.test', 'api_key' => 'oc-key-one',
             'enabled' => true, 'last_order_sync_at' => now()->subMinutes(5)]
        );

        OpenCartSetting::updateOrCreate(
            ['store_name' => 'Cable Loft'],
            ['base_url' => 'https://two.opencart.test', 'api_key' => 'oc-key-two',
             'enabled' => false, 'last_order_sync_at' => now()->subMinutes(5)]
        );
    }

    private function storeId(string $channel, string $name): int
    {
        return (int) ($channel === 'venta'
            ? VentaSetting::where('store_name', $name)->firstOrFail()->id
            : OpenCartSetting::where('store_name', $name)->firstOrFail()->id);
    }

    /** The two seeded store ids for a channel, first and second. */
    private function storeIds(string $channel): array
    {
        return $channel === 'venta'
            ? [$this->storeId('venta', 'Gear Depot'), $this->storeId('venta', 'Switch Bazaar')]
            : [$this->storeId('opencart', 'Parts Yard'), $this->storeId('opencart', 'Cable Loft')];
    }

    /**
     * The Overview link as it appears in the markup, href and quotes included.
     *
     * A bare URL substring stopped being evidence once the IA migration
     * (2026-08-08, Task 2) put the store workspace at /integrations/venta/2:
     * that string is now a PREFIX of /integrations/venta/2/orders, so
     * "contains the Overview URL" is satisfied by any sidebar link on the
     * page and "does not contain it" could never be true. Matching the whole
     * attribute keeps both directions honest, the same way the board
     * assertion further down already did.
     */
    private function overviewHref(string $key): string
    {
        return 'href="'.e(ChannelWorkspace::overviewUrl($key)).'"';
    }

    private function card(string $channel): IntegrationCard
    {
        foreach (app(IntegrationRegistry::class)->all() as $provider) {
            foreach ($provider->integrationCards() as $card) {
                if ($card->id === $channel) {
                    return $card;
                }
            }
        }

        $this->fail(ucfirst($channel).' did not register an IntegrationCard.');
    }

    /**
     * A genuine read-only operator for one channel: the card permission and
     * the orders permission, nothing else. Group names are unique per test
     * class so this suite never collides with the other workspace suites
     * when they share a process.
     */
    private function operator(string $channel): User
    {
        $group = UserGroup::create(['name' => 'Multistore '.$channel.' Operators']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_'.$channel, 'view_'.$channel.'_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * The narrow tier: the orders permission alone, NOT the card permission
     * visibleCards() wants. This app has no parent/child permission cascade,
     * so this user reaches the orders page its route allows and nothing else.
     */
    private function ordersOnlyOperator(string $channel): User
    {
        $group = UserGroup::create(['name' => 'Multistore '.$channel.' Order Desk']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_'.$channel.'_orders'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** Holds neither tier: the far side of every gate asserted here. */
    private function outsider(string $channel): User
    {
        $group = UserGroup::create(['name' => 'Multistore '.$channel.' Outsiders']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_dashboard'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public static function channelProvider(): array
    {
        return ['venta' => ['venta'], 'opencart' => ['opencart']];
    }

    /**
     * The part of the page an operator actually looks at: the channel
     * sidebar and the content region.
     *
     * The command palette is deliberately excluded. It is the app-wide jump
     * list, built from Navigation::groups(), and offering every store from
     * anywhere is exactly what it is for - store isolation is a statement
     * about the workspace, not about search. It also carries its entries as
     * a JSON blob with escaped slashes, so leaving it in would make a URL
     * assertion pass while testing nothing.
     */
    private function visibleChrome(string $html): string
    {
        // [^>]* so the tag may carry attributes: the channel shell became an
        // off-canvas drawer below 1024px and the <aside> now holds Alpine's
        // :class and an id. The classes are still required; only the
        // assumption that nothing else sits in the tag is relaxed.
        preg_match('/<aside class="x-sidebar x-sidebar--channel"[^>]*>(.*?)<\/aside>/s', $html, $sidebar);
        preg_match('/<main class="x-content[^"]*">(.*?)<\/main>/s', $html, $content);

        $this->assertNotEmpty($sidebar[1] ?? '', 'No channel sidebar rendered.');
        $this->assertNotEmpty($content[1] ?? '', 'No content region rendered.');

        return $sidebar[1].$content[1];
    }

    /** The three destinations a store workspace offers, in sidebar order. */
    private function expectedGroups(string $channel, int $storeId): array
    {
        return [
            ['Sell', 'Orders', route('ext.'.$channel.'.orders.index', ['store' => $storeId])],
            ['Catalogue', 'Product Groups', route('ext.'.$channel.'.product-groups.index', ['store' => $storeId])],
            ['Channel', 'Settings', route('ext.'.$channel.'.settings.show', ['store' => $storeId])],
        ];
    }

    // ------------------------------------------------------------------
    // The conversion signal
    // ------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_every_store_menu_item_declares_a_known_group(string $channel): void
    {
        $card = $this->card($channel);

        // Guards the premise: without stores this whole suite is vacuous.
        $this->assertCount(2, $card->stores, $channel.' did not build two store links.');

        foreach ($card->stores as $store) {
            $this->assertNotEmpty($store->menu, 'Store '.$store->id.' has no menu.');

            foreach ($store->menu as $item) {
                $this->assertContains(
                    $item->group,
                    ChannelWorkspace::GROUPS,
                    'Store menu item '.$item->label.' has group '.var_export($item->group, true)
                );
            }
        }
    }

    /**
     * A multi-store card converts on its STORE menus, not its own. Reading
     * the card menu here would call OpenCart unconverted (its only card-level
     * item is the global Reviews link, which stays ungrouped) while its
     * stores are fully converted.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_card_declares_a_workspace_through_its_stores(string $channel): void
    {
        $this->assertTrue(app(ChannelWorkspace::class)->hasWorkspace($this->card($channel)));
    }

    /**
     * The mirror: with no stores configured there is nothing to open, so the
     * channel has no workspace and the board keeps sending the operator to
     * the module page where a store can be created.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_a_channel_with_no_stores_has_no_workspace(string $channel): void
    {
        $channel === 'venta'
            ? VentaSetting::query()->delete()
            : OpenCartSetting::query()->delete();

        $this->assertFalse(app(ChannelWorkspace::class)->hasWorkspace($this->card($channel)));
    }

    // ------------------------------------------------------------------
    // Store scoping
    // ------------------------------------------------------------------

    /**
     * Every link in a store's sidebar carries THAT store's id. Asserted by
     * full URL equality rather than a substring, so a link pointing at the
     * sibling store cannot pass by containing the right route.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_sidebar_groups_carry_the_stores_own_params(string $channel): void
    {
        $user = $this->operator($channel);
        $card = $this->card($channel);
        $workspace = app(ChannelWorkspace::class);

        foreach ($this->storeIds($channel) as $storeId) {
            $groups = $workspace->groups($card, $user, $channel.':'.$storeId);

            $this->assertSame(
                ['Sell', 'Catalogue', 'Channel'],
                array_column($groups, 'label'),
                'Wrong groups for store '.$storeId
            );

            $flat = [];
            foreach ($groups as $group) {
                foreach ($group['items'] as $item) {
                    $flat[] = [$group['label'], $item['label'], $item['url']];
                }
            }

            $this->assertSame($this->expectedGroups($channel, $storeId), $flat);
        }
    }

    /**
     * The isolation rule, at the machinery level: one store's sidebar holds
     * no URL belonging to the other store. Asserted against the OTHER
     * store's real id, read back from the database, not a guessed one.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_one_stores_sidebar_holds_none_of_the_other_stores_links(string $channel): void
    {
        [$first, $second] = $this->storeIds($channel);
        $workspace = app(ChannelWorkspace::class);
        $card = $this->card($channel);
        $user = $this->operator($channel);

        $urlsFor = function (int $storeId) use ($workspace, $card, $user, $channel) {
            $urls = [];
            foreach ($workspace->groups($card, $user, $channel.':'.$storeId) as $group) {
                foreach ($group['items'] as $item) {
                    $urls[] = $item['url'];
                }
            }
            return $urls;
        };

        $firstUrls = $urlsFor($first);
        $secondUrls = $urlsFor($second);

        $this->assertCount(3, $firstUrls);
        $this->assertCount(3, $secondUrls);
        $this->assertSame([], array_intersect($firstUrls, $secondUrls));
    }

    /**
     * Health is read per store, from that store's own settings row. The
     * seeded states differ on purpose: a reading that ignored the store
     * would report the same word for both.
     */
    public function test_each_store_reports_its_own_state(): void
    {
        $workspace = app(ChannelWorkspace::class);
        $venta = $this->card('venta');
        $opencart = $this->card('opencart');

        [$ventaOne, $ventaTwo] = $this->storeIds('venta');
        [$ocOne, $ocTwo] = $this->storeIds('opencart');

        $this->assertSame('connected', $workspace->state($venta, 'venta:'.$ventaOne));
        // Never synced, so stale by the same 24 hour threshold the board uses.
        $this->assertSame('attention', $workspace->state($venta, 'venta:'.$ventaTwo));

        $this->assertSame('connected', $workspace->state($opencart, 'opencart:'.$ocOne));
        // Created but not enabled yet: setup, whatever its columns hold.
        $this->assertSame('setup', $workspace->state($opencart, 'opencart:'.$ocTwo));
    }

    public function test_each_store_reports_its_own_last_sync(): void
    {
        $workspace = app(ChannelWorkspace::class);
        $card = $this->card('venta');
        [$first, $second] = $this->storeIds('venta');

        $lastSync = function (array $health) {
            foreach ($health as $row) {
                if ($row['label'] === 'Last sync') {
                    return $row['value'];
                }
            }
            return null;
        };

        $this->assertNotNull($lastSync($workspace->health($card, 'venta:'.$first)));
        // Never synced, so not tracked rather than a fabricated timestamp.
        $this->assertNull($lastSync($workspace->health($card, 'venta:'.$second)));
    }

    // ------------------------------------------------------------------
    // The workspace page
    // ------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_each_stores_workspace_renders_with_its_own_name_and_links(string $channel): void
    {
        $user = $this->operator($channel);
        $card = $this->card($channel);

        foreach ($card->stores as $store) {
            $storeId = (int) substr($store->id, strpos($store->id, ':') + 1);

            $response = $this->actingAs($user)->get('/integrations/'.ChannelWorkspace::pathKey($store->id));

            $response->assertOk();
            $response->assertSee($store->label);
            $response->assertSee('<span class="x-cnav__group-label">Sell</span>', false);
            $response->assertSee('<span class="x-cnav__group-label">Catalogue</span>', false);
            $response->assertSee('<span class="x-cnav__group-label">Channel</span>', false);

            foreach ($this->expectedGroups($channel, $storeId) as [, , $url]) {
                $response->assertSee(e($url), false);
            }
        }
    }

    /**
     * The isolation rule on the rendered page: standing in one store, the
     * other store's name and its three destinations are nowhere on screen.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_a_stores_workspace_never_shows_the_sibling_store(string $channel): void
    {
        $card = $this->card($channel);
        [$firstStore, $secondStore] = $card->stores;
        [$first, $second] = $this->storeIds($channel);

        $response = $this->actingAs($this->operator($channel))->get('/integrations/'.ChannelWorkspace::pathKey($firstStore->id));

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        // Guards the premise: this IS the first store's page.
        $this->assertStringContainsString($firstStore->label, $chrome);
        $this->assertStringContainsString(
            $this->overviewHref($channel.':'.$first),
            $chrome
        );

        $this->assertStringNotContainsString($secondStore->label, $chrome);

        foreach ($this->expectedGroups($channel, $second) as [, $label, $url]) {
            $this->assertStringNotContainsString($url, $chrome, $label.' leaked in from the sibling store.');
        }

        // And the sibling's Overview is not reachable from here either.
        $this->assertStringNotContainsString(
            $this->overviewHref($channel.':'.$second),
            $chrome
        );
    }

    /**
     * OpenCart's Reviews item lives on the top card, not on any store, and is
     * global to the app rather than to one shop. It stays in the main nav's
     * Catalogue group and out of every store workspace.
     */
    public function test_the_global_reviews_item_stays_out_of_the_store_workspaces(): void
    {
        $group = UserGroup::create(['name' => 'Multistore OpenCart Reviewers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_opencart', 'view_opencart_orders', 'view_reviews'])
                ->pluck('id')
                ->all()
        );
        $user = User::factory()->create(['user_group_id' => $group->id]);

        // Guards the premise: this user genuinely holds the Reviews
        // permission, so its absence below is the grouping and not the gate.
        $this->assertTrue($user->hasPermission('view_reviews'));

        [$first] = $this->storeIds('opencart');

        $response = $this->actingAs($user)->get('/integrations/opencart/'.$first);

        $response->assertOk();
        $response->assertDontSee(e(route('ext.opencart.reviews.index')), false);
        $response->assertDontSee('>Reviews</a>', false);
    }

    // ------------------------------------------------------------------
    // Keys that must not resolve
    // ------------------------------------------------------------------

    /**
     * Every key that must resolve to nothing, built from the store ids this
     * test actually seeded.
     *
     * Building them at runtime is the whole point. A static list like
     * 'venta:2:3' looked equivalent and was not: InnoDB does not reset
     * AUTO_INCREMENT when RefreshDatabase rolls a test back, so by the time
     * a later data set ran, the seeded stores were ids 15 and 16 and the
     * literal 2 named nothing. The malformed-key assertions all passed
     * without ever reaching the store lookup they claim to guard. These are
     * pinned to the live id so a loosening of the parser is caught.
     *
     * @return array<string, string>
     */
    private function keysThatMustNotResolve(int $ventaStoreId, int $opencartStoreId): array
    {
        return [
            // No storeless page exists on either channel.
            'a bare multi-store channel' => 'venta',
            'a bare multi-store channel (opencart)' => 'opencart',
            // Well formed, but no such store.
            'an unknown venta store' => 'venta:'.($ventaStoreId + 9000),
            'an unknown opencart store' => 'opencart:'.($opencartStoreId + 9000),
            // Malformed, and every one of these names a REAL store the
            // moment the parser stops being strict.
            'an empty store' => 'venta:',
            'a non numeric store' => 'venta:abc',
            'a second suffix after a real store' => 'venta:'.$ventaStoreId.':3',
            'a real store with trailing text' => 'venta:'.$ventaStoreId.'x',
            'store zero' => 'venta:0',
            'a negative store' => 'venta:-'.$ventaStoreId,
            // A store suffix on a single-store channel names nothing: Shopee
            // is one shop, and shopee:1 is not another name for it.
            'a suffix on a single-store channel' => 'shopee:'.$ventaStoreId,
        ];
    }

    public function test_a_key_that_names_no_store_is_not_found(): void
    {
        $group = UserGroup::create(['name' => 'Multistore Broad Operators']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_venta', 'view_venta_orders', 'view_opencart', 'view_opencart_orders', 'view_shopee'])
                ->pluck('id')
                ->all()
        );
        $user = User::factory()->create(['user_group_id' => $group->id]);

        [$ventaStore] = $this->storeIds('venta');
        [$opencartStore] = $this->storeIds('opencart');

        // Guards the premise: this user can open a real store workspace AND
        // a real single-store one, so every 404 below is about the key and
        // not about the user. The Shopee half is what makes the suffix case
        // a statement about the suffix rather than about an absent card.
        $this->actingAs($user)->get('/integrations/venta/'.$ventaStore)->assertOk();
        $this->actingAs($user)->get('/integrations/opencart/'.$opencartStore)->assertOk();
        $this->actingAs($user)->get('/integrations/shopee')->assertOk();

        foreach ($this->keysThatMustNotResolve($ventaStore, $opencartStore) as $name => $key) {
            $this->actingAs($user)
                ->get('/integrations/'.ChannelWorkspace::pathKey($key))
                ->assertNotFound('Expected '.$name.' ('.$key.') to be not found.');
        }
    }

    // ------------------------------------------------------------------
    // The pages inside the workspace
    // ------------------------------------------------------------------

    /** Every converted page of both channels, per store. */
    private function pagesFor(string $channel, int $storeId): array
    {
        return [
            'orders' => '/integrations/'.$channel.'/'.$storeId.'/orders',
            'product groups' => '/integrations/'.$channel.'/'.$storeId.'/product-groups',
            'settings' => '/integrations/'.$channel.'/'.$storeId.'/settings',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_every_page_renders_inside_that_stores_workspace(string $channel): void
    {
        $user = $this->operator($channel);
        $card = $this->card($channel);

        foreach ($card->stores as $store) {
            $storeId = (int) substr($store->id, strpos($store->id, ':') + 1);

            foreach ($this->pagesFor($channel, $storeId) as $name => $path) {
                $response = $this->actingAs($user)->get($path);

                $response->assertOk();
                $response->assertSee('x-cnav', false);
                $response->assertSee('All channels');
                // The sidebar head names the store this page belongs to.
                $response->assertSee('<span class="x-cnav__store">'.e($store->label).'</span>', false);
                // data-nav, not data-nav-group: partials/nav.blade.php always
                // renders the outer <nav class="x-nav" data-nav> wrapper, so
                // its absence is real evidence the global sidebar did not
                // render.
                $response->assertDontSee('data-nav', false, 'The global nav rendered on the '.$name.' page.');
            }
        }
    }

    /**
     * The chrome owns the "Integrations / Venta / Venta Store Two" trail, so
     * each view supplies only its leaf. Before this task the views carried
     * the whole trail themselves, which would now read it twice.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_breadcrumb_names_the_channel_and_the_store_once_each(string $channel): void
    {
        $card = $this->card($channel);
        $store = $card->stores[1];
        $storeId = (int) substr($store->id, strpos($store->id, ':') + 1);
        $user = $this->operator($channel);

        foreach ($this->pagesFor($channel, $storeId) as $path) {
            $html = $this->actingAs($user)->get($path)->getContent();

            $crumb = preg_match('/<div class="x-topbar__crumb">(.*?)<\/div>/s', $html, $m)
                ? strip_tags($m[1])
                : $this->fail('No breadcrumb rendered on '.$path);

            $flat = preg_replace('/\s+/', ' ', trim($crumb));

            $this->assertSame(1, substr_count($crumb, 'Integrations'), 'Trail repeats on '.$path.': '.$flat);
            $this->assertSame(1, substr_count($crumb, $card->name), 'Channel repeats on '.$path.': '.$flat);
            $this->assertSame(1, substr_count($crumb, $store->label), 'Store missing or repeated on '.$path.': '.$flat);
        }
    }

    // ------------------------------------------------------------------
    // Gates, both directions
    // ------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_an_orders_only_operator_is_refused_the_store_workspace(string $channel): void
    {
        [$first] = $this->storeIds($channel);
        $user = $this->ordersOnlyOperator($channel);

        // Guards the premise: this user holds the orders permission and not
        // the card permission, so the 404 is the card gate.
        $this->assertTrue($user->hasPermission('view_'.$channel.'_orders'));
        $this->assertFalse($user->hasPermission('view_'.$channel));

        $this->actingAs($user)->get('/integrations/'.$channel.'/'.$first)->assertNotFound();
    }

    /**
     * The mirror of the test above: the same narrow user still reaches the
     * page their route allows, gets the channel sidebar on it, and sees only
     * the group their permission covers - with no Overview link, because
     * that destination would 404 for them.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_an_orders_only_operator_reaches_orders_with_a_narrow_sidebar(string $channel): void
    {
        [$first] = $this->storeIds($channel);

        $response = $this->actingAs($this->ordersOnlyOperator($channel))
            ->get('/integrations/'.$channel.'/'.$first.'/orders');

        $response->assertOk();
        $response->assertSee('x-cnav', false);

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringContainsString('<span class="x-cnav__group-label">Sell</span>', $chrome);
        $this->assertStringContainsString(route('ext.'.$channel.'.orders.index', ['store' => $first]), $chrome);

        $this->assertStringNotContainsString('<span class="x-cnav__group-label">Catalogue</span>', $chrome);
        $this->assertStringNotContainsString('<span class="x-cnav__group-label">Channel</span>', $chrome);
        $this->assertStringNotContainsString(route('ext.'.$channel.'.product-groups.index', ['store' => $first]), $chrome);
        $this->assertStringNotContainsString(route('ext.'.$channel.'.settings.show', ['store' => $first]), $chrome);
        $this->assertStringNotContainsString(
            $this->overviewHref($channel.':'.$first),
            $chrome
        );
    }

    /**
     * A user holding the card permission DOES get the Overview link, so the
     * assertion above is about the gate rather than about a link nobody ever
     * sees.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_a_permitted_operator_gets_the_overview_link_for_this_store(string $channel): void
    {
        [$first, $second] = $this->storeIds($channel);

        $response = $this->actingAs($this->operator($channel))
            ->get('/integrations/'.$channel.'/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringContainsString(
            $this->overviewHref($channel.':'.$first),
            $chrome
        );
        // And it is THIS store's Overview, not the sibling's.
        $this->assertStringNotContainsString(
            $this->overviewHref($channel.':'.$second),
            $chrome
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_a_user_without_either_permission_reaches_nothing(string $channel): void
    {
        [$first] = $this->storeIds($channel);
        $user = $this->outsider($channel);

        $this->assertFalse($user->hasPermission('view_'.$channel));
        $this->assertFalse($user->hasPermission('view_'.$channel.'_orders'));

        $this->actingAs($user)->get('/integrations/'.$channel.'/'.$first)->assertNotFound();

        // The orders route's own perm: middleware denies before any view
        // renders, so this is a redirect rather than a 404.
        $this->actingAs($user)->get('/integrations/'.$channel.'/'.$first.'/orders')->assertRedirect();
    }

    // ------------------------------------------------------------------
    // The board and the nav agree with the workspace
    // ------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_channels_nav_offers_one_seller_center_per_store(string $channel): void
    {
        $card = $this->card($channel);

        $this->actingAs($this->operator($channel));

        $channels = collect(\App\Support\Navigation::groups())->firstWhere('label', 'Channels');

        $this->assertNotNull($channels, 'The nav has no Channels group.');

        foreach ($card->stores as $store) {
            $label = $card->name.' '.$store->label;
            $entry = collect($channels['items'])->firstWhere('label', $label);

            $this->assertNotNull($entry, 'The nav has no entry for '.$label);
            $this->assertSame('integrations.workspace', $entry['route']);
            $this->assertSame(
                ChannelWorkspace::overviewUrl($store->id),
                route($entry['route'], $entry['params'])
            );
        }

        // The bare channel is not offered, because it has nowhere to land.
        $this->assertNull(collect($channels['items'])->firstWhere('label', $card->name));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_board_opens_each_store_in_its_own_workspace(string $channel): void
    {
        $card = $this->card($channel);

        $response = $this->actingAs($this->operator($channel))->get('/integrations');

        $response->assertOk();

        foreach ($card->stores as $store) {
            $response->assertSee($this->overviewHref($store->id), false);
        }

        // And never the storeless key, which 404s. Quoted, because
        // /integrations/venta is a prefix of /integrations/venta/2 and a bare
        // substring test could never fail.
        $response->assertDontSee($this->overviewHref($channel), false);
    }

    // ------------------------------------------------------------------
    // Round 2, Task 2: the orders pages, per store, on the seller center.
    //
    // The hub side of the same panels is covered in FulfilmentHubTest. What
    // is specific here is that a store's seller center carries the store in
    // its URI path rather than in a query param, and that the
    // cross-marketplace tab strip these two pages used to include is gone.
    // ------------------------------------------------------------------

    /** Manage tier for a channel's orders, which is what renders its writes. */
    private function ordersManager(string $channel): User
    {
        $group = UserGroup::create(['name' => 'Multistore '.$channel.' Order Managers']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_'.$channel, 'manage_'.$channel.'_orders', 'manage_orders'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** One Venta order in a named store. */
    private function ventaOrder(int $storeId, string $customer, string $status): \Extensions\venta\Models\VentaOrder
    {
        return \Extensions\venta\Models\VentaOrder::create([
            'venta_setting_id'   => $storeId,
            'venta_order_id'     => ($storeId * 100000) + random_int(1, 99999),
            'venta_order_number' => ($storeId * 100000) + random_int(1, 99999),
            'status'             => $status,
            'status_id'          => 1,
            'customer_name'      => $customer,
            'total'              => 1234.50,
            'payment_method'     => 'Cash on delivery',
            'order_created_at'   => now()->subDay(),
        ]);
    }

    /**
     * One core catalog order tagged to an OpenCart store. The legacy
     * OpenCart-derived `order` table has many NOT NULL columns with no
     * default and MySQL's STRICT_TRANS_TABLES rejects an insert that omits
     * them; the blanks exist only to satisfy the schema.
     */
    private function opencartOrder(int $storeId, string $firstname, string $marketplaceOrderId): int
    {
        $prefix = (string) config('catalog.prefix');

        return (int) \Illuminate\Support\Facades\DB::table($prefix.'order')->insertGetId([
            'firstname' => $firstname, 'lastname' => 'Buyer',
            'email' => strtolower($firstname).'@example.test',
            'marketplace_source' => 'opencart:'.$storeId,
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
     * Both channels' orders pages render through the shared panel partial,
     * per store, and every control in it points at THAT store.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_orders_page_renders_the_shared_panel_per_store(string $channel): void
    {
        $user = $this->ordersManager($channel);

        foreach ($this->storeIds($channel) as $storeId) {
            $response = $this->actingAs($user)->get('/integrations/'.$channel.'/'.$storeId.'/orders');

            $response->assertOk();
            // The panel's own root, which is also what the page CSS and (for
            // Venta) the page module scope themselves to.
            $response->assertSee('id="'.$channel.'-orders-page"', false);
            // The one filter form the panel owns, posting back to this
            // store's own route rather than to the channel's first store.
            $response->assertSee(
                '<form method="GET" action="'.e(route('ext.'.$channel.'.orders.index', ['store' => $storeId])).'" class="x-filters" data-desk-tool id="orders-filter">',
                false
            );
            // The seller center passes no hidden context params, so the panel
            // renders none. This is the mirror of the hub's channel input.
            $response->assertDontSee('<input type="hidden" name="channel"', false);
        }
    }

    /**
     * The cross-marketplace tab strip is gone from both orders pages.
     *
     * It was Task 1's one accepted exception, kept because it was then the
     * only way to switch stores from inside orders. The workspace sidebar
     * and the fulfilment hub are that now, so its presence would be a second
     * navigation system inside a page that already sits in one.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_orders_page_no_longer_carries_the_cross_marketplace_strip(string $channel): void
    {
        [$first] = $this->storeIds($channel);

        $response = $this->actingAs($this->operator($channel))
            ->get('/integrations/'.$channel.'/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        // The strip's own root and card classes, read off
        // integrations/partials/_tab_strip rather than guessed: an assertion
        // against a class name that partial never emits would pass whether
        // or not the strip was still included.
        $this->assertStringNotContainsString('ord-tab-strip', $chrome);
        $this->assertStringNotContainsString('ord-tab-card', $chrome);
        // And the strip's Summary card, which is its first entry and the one
        // link it always carries.
        $this->assertStringNotContainsString(
            'href="'.e(route('integrations.orders')).'"',
            $chrome
        );
    }

    /**
     * Store isolation on the seller center: standing in one Venta store, the
     * other store's orders are nowhere in the sidebar or the content.
     *
     * Asserted against the live order numbers read back off the rows, for
     * the same reason the key assertions are: AUTO_INCREMENT does not reset
     * between tests and a literal would name nothing.
     */
    public function test_a_venta_stores_orders_page_holds_none_of_the_other_stores_orders(): void
    {
        [$first, $second] = $this->storeIds('venta');

        $mine = $this->ventaOrder($first, 'Depot Buyer', 'Pending');
        $theirs = $this->ventaOrder($second, 'Bazaar Buyer', 'Pending');

        $response = $this->actingAs($this->ordersManager('venta'))
            ->get('/integrations/venta/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        // Guards the premise: this page is not simply empty.
        $this->assertStringContainsString('Depot Buyer', $chrome);
        $this->assertStringContainsString((string) $mine->venta_order_number, $chrome);

        $this->assertStringNotContainsString('Bazaar Buyer', $chrome);
        $this->assertStringNotContainsString((string) $theirs->venta_order_number, $chrome);
        // And no control on the page acts on the sibling store.
        $this->assertStringNotContainsString(route('ext.venta.orders.fetch', ['store' => $second]), $chrome);
        $this->assertStringNotContainsString(route('ext.venta.orders.bulk_delete', ['store' => $second]), $chrome);
    }

    /** The same rule for OpenCart, whose rows are core catalog orders. */
    public function test_an_opencart_stores_orders_page_holds_none_of_the_other_stores_orders(): void
    {
        [$first, $second] = $this->storeIds('opencart');

        $mineId = $this->opencartOrder($first, 'Yardley', 'OC-YARD-1');
        $theirsId = $this->opencartOrder($second, 'Loftus', 'OC-LOFT-1');

        $response = $this->actingAs($this->ordersManager('opencart'))
            ->get('/integrations/opencart/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringContainsString('Yardley', $chrome);
        $this->assertStringContainsString('OC-YARD-1', $chrome);

        $this->assertStringNotContainsString('Loftus', $chrome);
        $this->assertStringNotContainsString('OC-LOFT-1', $chrome);
        // Quoted as a full href: /sales/orders/1 is a prefix of
        // /sales/orders/12, so a bare substring test on the sibling's URL
        // could pass or fail on nothing but which ids the run allocated.
        $this->assertStringContainsString('href="'.e(route('orders.show', $mineId)).'"', $chrome);
        $this->assertStringNotContainsString('href="'.e(route('orders.show', $theirsId)).'"', $chrome);
    }

    /**
     * The write controls a Venta store's orders page offers are the manage
     * tier's, and they act on THIS store.
     *
     * The old page hardcoded its permission flag to true, so a read-only
     * operator was shown a fetch bar and a row of Delete buttons that all
     * 403 on submit. Both directions are asserted, because a flag that is
     * always false would pass the negative half on its own.
     */
    public function test_only_the_manage_tier_gets_the_venta_write_controls(): void
    {
        [$first] = $this->storeIds('venta');
        $this->ventaOrder($first, 'Depot Buyer', 'Pending');

        $manager = $this->ordersManager('venta');
        $viewer = $this->operator('venta');

        $this->assertTrue($manager->hasPermission('manage_venta_orders'));
        $this->assertFalse($viewer->hasPermission('manage_venta_orders'));

        $managerView = $this->actingAs($manager)->get('/integrations/venta/'.$first.'/orders');
        $managerView->assertOk();
        $managerView->assertSee('id="formFetchVentaOrders"', false);
        $managerView->assertSee(e(route('ext.venta.orders.fetch', ['store' => $first])), false);
        $managerView->assertSee(e(route('ext.venta.orders.bulk_delete', ['store' => $first])), false);
        // Destructive actions carry the project's confirm convention rather
        // than a native dialog.
        $managerView->assertSee('data-confirm=', false);

        $viewerView = $this->actingAs($viewer)->get('/integrations/venta/'.$first.'/orders');
        $viewerView->assertOk();
        $viewerView->assertDontSee('id="formFetchVentaOrders"', false);
        $viewerView->assertDontSee(e(route('ext.venta.orders.bulk_delete', ['store' => $first])), false);
    }

    /**
     * OpenCart's page offers no command bar to anyone: the channel declares
     * no order write route for one to submit to. Its row actions are core
     * order routes and follow the core orders permission.
     */
    public function test_the_opencart_orders_page_has_no_command_bar_and_core_gated_row_actions(): void
    {
        [$first] = $this->storeIds('opencart');
        $orderId = $this->opencartOrder($first, 'Yardley', 'OC-YARD-1');

        $manager = $this->ordersManager('opencart');
        $viewer = $this->operator('opencart');

        $managerView = $this->actingAs($manager)->get('/integrations/opencart/'.$first.'/orders');
        $managerView->assertOk();
        $managerView->assertDontSee('x-cmdbar', false);
        $managerView->assertSee('href="'.e(route('orders.edit', $orderId)).'"', false);
        $managerView->assertSee('data-confirm=', false);

        // The read tier gets the list and no writes at all.
        $viewerView = $this->actingAs($viewer)->get('/integrations/opencart/'.$first.'/orders');
        $viewerView->assertOk();
        $viewerView->assertDontSee('x-cmdbar', false);
        $viewerView->assertDontSee('href="'.e(route('orders.edit', $orderId)).'"', false);
    }

    /**
     * Segment, sort and filter links on the seller center all carry the
     * store in the URI path and never the sibling's.
     */
    public function test_the_venta_panels_own_links_stay_on_this_store(): void
    {
        [$first, $second] = $this->storeIds('venta');
        $this->ventaOrder($first, 'Depot Buyer', 'Pending');

        $response = $this->actingAs($this->ordersManager('venta'))
            ->get('/integrations/venta/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());
        $own = route('ext.venta.orders.index', ['store' => $first]);
        $sibling = route('ext.venta.orders.index', ['store' => $second]);

        // A status segment link: status= only ever appears there.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(e($own), '/').'\?[^"]*status=/',
            $chrome,
            'A status segment link did not stay on this store.'
        );
        // A column sort link.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(e($own), '/').'\?[^"]*sort=total/',
            $chrome,
            'A column sort link did not stay on this store.'
        );

        $this->assertStringNotContainsString($sibling, $chrome);
    }
}
