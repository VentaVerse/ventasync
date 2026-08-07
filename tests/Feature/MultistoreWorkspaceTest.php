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
        preg_match('/<aside class="x-sidebar x-sidebar--channel">(.*?)<\/aside>/s', $html, $sidebar);
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

            $response = $this->actingAs($user)->get('/integrations/'.$store->id);

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

        $response = $this->actingAs($this->operator($channel))->get('/integrations/'.$firstStore->id);

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        // Guards the premise: this IS the first store's page.
        $this->assertStringContainsString($firstStore->label, $chrome);
        $this->assertStringContainsString(
            e(route('integrations.workspace', ['channel' => $channel.':'.$first])),
            $chrome
        );

        $this->assertStringNotContainsString($secondStore->label, $chrome);

        foreach ($this->expectedGroups($channel, $second) as [, $label, $url]) {
            $this->assertStringNotContainsString($url, $chrome, $label.' leaked in from the sibling store.');
        }

        // And the sibling's Overview is not reachable from here either.
        $this->assertStringNotContainsString(
            e(route('integrations.workspace', ['channel' => $channel.':'.$second])),
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

        $response = $this->actingAs($user)->get('/integrations/opencart:'.$first);

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
        $this->actingAs($user)->get('/integrations/venta:'.$ventaStore)->assertOk();
        $this->actingAs($user)->get('/integrations/opencart:'.$opencartStore)->assertOk();
        $this->actingAs($user)->get('/integrations/shopee')->assertOk();

        foreach ($this->keysThatMustNotResolve($ventaStore, $opencartStore) as $name => $key) {
            $this->actingAs($user)
                ->get('/integrations/'.$key)
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
            'orders' => '/'.$channel.'/'.$storeId.'/orders',
            'product groups' => '/'.$channel.'/'.$storeId.'/product-groups',
            'settings' => '/'.$channel.'/settings/'.$storeId,
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

        $this->actingAs($user)->get('/integrations/'.$channel.':'.$first)->assertNotFound();
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
            ->get('/'.$channel.'/'.$first.'/orders');

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
            route('integrations.workspace', ['channel' => $channel.':'.$first]),
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
            ->get('/'.$channel.'/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringContainsString(
            route('integrations.workspace', ['channel' => $channel.':'.$first]),
            $chrome
        );
        // And it is THIS store's Overview, not the sibling's.
        $this->assertStringNotContainsString(
            route('integrations.workspace', ['channel' => $channel.':'.$second]),
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

        $this->actingAs($user)->get('/integrations/'.$channel.':'.$first)->assertNotFound();

        // The orders route's own perm: middleware denies before any view
        // renders, so this is a redirect rather than a 404.
        $this->actingAs($user)->get('/'.$channel.'/'.$first.'/orders')->assertRedirect();
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
                route('integrations.workspace', ['channel' => $store->id]),
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
            $response->assertSee(e(route('integrations.workspace', ['channel' => $store->id])), false);
        }

        // And never the storeless key, which 404s. Quoted, because
        // /integrations/venta is a prefix of /integrations/venta:2 and a bare
        // substring test could never fail.
        $response->assertDontSee('href="'.e(route('integrations.workspace', ['channel' => $channel])).'"', false);
    }
}
