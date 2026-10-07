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
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\VentaCartExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultistoreWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);

        $providers = [
            'ventacart' => VentaCartExtension::class,
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

    private function seedStores(): void
    {
        VentaCartSetting::updateOrCreate(
            ['store_name' => 'Gear Depot'],
            ['base_url' => 'https://one.ventacart.test', 'api_token' => 'ventacart-token-one',
             'enabled' => true, 'last_order_sync_at' => now()->subMinutes(5)]
        );

        VentaCartSetting::updateOrCreate(
            ['store_name' => 'Switch Bazaar'],
            ['base_url' => 'https://two.ventacart.test', 'api_token' => 'ventacart-token-two',
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
        return (int) ($channel === 'ventacart'
            ? VentaCartSetting::where('store_name', $name)->firstOrFail()->id
            : OpenCartSetting::where('store_name', $name)->firstOrFail()->id);
    }

    private function storeIds(string $channel): array
    {
        return $channel === 'ventacart'
            ? [$this->storeId('ventacart', 'Gear Depot'), $this->storeId('ventacart', 'Switch Bazaar')]
            : [$this->storeId('opencart', 'Parts Yard'), $this->storeId('opencart', 'Cable Loft')];
    }

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

    private function operator(string $channel): User
    {
        $group = UserGroup::create(['name' => 'Multistore '.$channel.' Operators']);

        $keys = array_values(array_filter(
            app(\App\Services\PermissionCatalogue::class)->keys(),
            fn ($k) => str_starts_with($k, 'view_'.$channel.'/')
        ));

        $group->permissions()->attach(
            Permission::whereIn('key', $keys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function ordersOnlyOperator(string $channel): User
    {
        $group = UserGroup::create(['name' => 'Multistore '.$channel.' Order Desk']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_'.$channel.'/order'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

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
        return ['ventacart' => ['ventacart'], 'opencart' => ['opencart']];
    }

    private function visibleChrome(string $html): string
    {
        preg_match('/<div class="x-chband">(.*?)<main\b/s', $html, $band);
        preg_match('/<main\b[^>]*class="x-content[^"]*"[^>]*>(.*?)<\/main>/s', $html, $content);

        $this->assertNotEmpty($band[1] ?? '', 'No channel menubar rendered.');
        $this->assertNotEmpty($content[1] ?? '', 'No content region rendered.');

        return $band[1].$content[1];
    }

    private function expectedGroups(string $channel, int $storeId): array
    {
        $rows = [
            ['Sell', 'Orders', route('ext.'.$channel.'.orders.index', ['store' => $storeId])],
        ];
        if ($channel === 'ventacart') {
            $rows[] = ['Catalog', 'Listings', route('ext.ventacart.listings.index', ['store' => $storeId])];
        }
        $rows[] = ['Catalog', 'Product Groups', route('ext.'.$channel.'.product-groups.index', ['store' => $storeId])];
        if (\Illuminate\Support\Facades\Route::has('ext.'.$channel.'.description-templates.index')) {
            $rows[] = ['Catalog', 'Description Templates', route('ext.'.$channel.'.description-templates.index', ['store' => $storeId])];
        }
        if (\Illuminate\Support\Facades\Route::has('ext.'.$channel.'.watermarks.index')) {
            $rows[] = ['Catalog', 'Watermarks', route('ext.'.$channel.'.watermarks.index', ['store' => $storeId])];
        }
        $rows[] = ['Catalog', 'Import', route('ext.'.$channel.'.products.import', ['store' => $storeId])];
        $rows[] = ['Channel', 'Settings', route('ext.'.$channel.'.settings.show', ['store' => $storeId])];

        return $rows;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_every_store_menu_item_declares_a_known_group(string $channel): void
    {
        $card = $this->card($channel);

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

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_card_declares_a_workspace_through_its_stores(string $channel): void
    {
        $this->assertTrue(app(ChannelWorkspace::class)->hasWorkspace($this->card($channel)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_a_channel_with_no_stores_has_no_workspace(string $channel): void
    {
        $channel === 'ventacart'
            ? VentaCartSetting::query()->delete()
            : OpenCartSetting::query()->delete();

        $this->assertFalse(app(ChannelWorkspace::class)->hasWorkspace($this->card($channel)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_sidebar_groups_carry_the_stores_own_params(string $channel): void
    {
        $user = $this->operator($channel);
        $card = $this->card($channel);
        $workspace = app(ChannelWorkspace::class);

        foreach ($this->storeIds($channel) as $storeId) {
            $groups = $workspace->groups($card, $user, $channel.':'.$storeId);

            $this->assertSame(
                ['Sell', 'Catalog', 'Channel'],
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

        $this->assertCount(count($this->expectedGroups($channel, $first)), $firstUrls);
        $this->assertCount(count($this->expectedGroups($channel, $second)), $secondUrls);
        $this->assertSame([], array_intersect($firstUrls, $secondUrls));
    }

    public function test_each_store_reports_its_own_state(): void
    {
        $workspace = app(ChannelWorkspace::class);
        $ventacart = $this->card('ventacart');
        $opencart = $this->card('opencart');

        [$ventaCartOne, $ventaCartTwo] = $this->storeIds('ventacart');
        [$ocOne, $ocTwo] = $this->storeIds('opencart');

        $this->assertSame('connected', $workspace->state($ventacart, 'ventacart:'.$ventaCartOne));
        $this->assertSame('attention', $workspace->state($ventacart, 'ventacart:'.$ventaCartTwo));

        $this->assertSame('connected', $workspace->state($opencart, 'opencart:'.$ocOne));
        $this->assertSame('setup', $workspace->state($opencart, 'opencart:'.$ocTwo));
    }

    public function test_each_store_reports_its_own_last_sync(): void
    {
        $workspace = app(ChannelWorkspace::class);
        $card = $this->card('ventacart');
        [$first, $second] = $this->storeIds('ventacart');

        $lastSync = function (array $health) {
            foreach ($health as $row) {
                if ($row['label'] === 'Last sync') {
                    return $row['value'];
                }
            }
            return null;
        };

        $this->assertNotNull($lastSync($workspace->health($card, 'ventacart:'.$first)));
        $this->assertNull($lastSync($workspace->health($card, 'ventacart:'.$second)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_each_stores_workspace_renders_with_its_own_name_and_links(string $channel): void
    {
        $user = $this->operator($channel);
        $card = $this->card($channel);

        foreach ($card->stores as $store) {
            $storeId = (int) substr($store->id, strpos($store->id, ':') + 1);

            $response = $this->actingAs($user)->get('/channels/'.ChannelWorkspace::pathKey($store->id));

            $response->assertOk();
            $response->assertSee($store->label);
            $response->assertSee('>Orders</a>', false);
            if ($channel === 'ventacart') {
                $response->assertSee('data-chnav-group="Catalog"', false);
            } else {
                $response->assertSee('>Product Groups</a>', false);
            }
            $response->assertSee('data-chnav-group="Settings"', false);

            foreach ($this->expectedGroups($channel, $storeId) as [, , $url]) {
                $response->assertSee(e($url), false);
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_a_stores_workspace_never_shows_the_sibling_store(string $channel): void
    {
        $card = $this->card($channel);
        [$firstStore, $secondStore] = $card->stores;
        [$first, $second] = $this->storeIds($channel);

        $response = $this->actingAs($this->operator($channel))->get('/channels/'.ChannelWorkspace::pathKey($firstStore->id));

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringContainsString($firstStore->label, $chrome);
        $this->assertStringContainsString(
            $this->overviewHref($channel.':'.$first),
            $chrome
        );

        $this->assertStringNotContainsString($secondStore->label, $chrome);

        foreach ($this->expectedGroups($channel, $second) as [, $label, $url]) {
            $this->assertStringNotContainsString($url, $chrome, $label.' leaked in from the sibling store.');
        }

        $this->assertStringNotContainsString(
            $this->overviewHref($channel.':'.$second),
            $chrome
        );
    }

    public function test_the_global_reviews_item_stays_out_of_the_store_workspaces(): void
    {
        $group = UserGroup::create(['name' => 'Multistore OpenCart Reviewers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_opencart/dashboard', 'view_opencart/settings', 'view_opencart/product_group', 'view_opencart/order', 'view_opencart/review'])
                ->pluck('id')
                ->all()
        );
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $this->assertTrue($user->hasPermission('view_opencart/review'));

        [$first] = $this->storeIds('opencart');

        $response = $this->actingAs($user)->get('/channels/opencart/'.$first);

        $response->assertOk();
        $chrome = $this->visibleChrome($response->getContent());
        $this->assertStringNotContainsString(e(route('ext.opencart.reviews.index')), $chrome);
        $this->assertStringNotContainsString('>Reviews</a>', $chrome);
    }

    private function keysThatMustNotResolve(int $ventaCartStoreId, int $opencartStoreId): array
    {
        return [
            'a bare multi-store channel' => 'ventacart',
            'a bare multi-store channel (opencart)' => 'opencart',
            'an unknown ventacart store' => 'ventacart:'.($ventaCartStoreId + 9000),
            'an unknown opencart store' => 'opencart:'.($opencartStoreId + 9000),
            'an empty store' => 'ventacart:',
            'a non numeric store' => 'ventacart:abc',
            'a second suffix after a real store' => 'ventacart:'.$ventaCartStoreId.':3',
            'a real store with trailing text' => 'ventacart:'.$ventaCartStoreId.'x',
            'store zero' => 'ventacart:0',
            'a negative store' => 'ventacart:-'.$ventaCartStoreId,
            'a suffix on a single-store channel' => 'shopee:'.$ventaCartStoreId,
        ];
    }

    public function test_a_key_that_names_no_store_is_not_found(): void
    {
        $group = UserGroup::create(['name' => 'Multistore Broad Operators']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_ventacart/dashboard', 'view_opencart/dashboard', 'view_ventacart/settings', 'view_ventacart/product_group', 'view_ventacart/order', 'view_opencart/settings', 'view_opencart/product_group', 'view_opencart/order', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/dashboard'])
                ->pluck('id')
                ->all()
        );
        $user = User::factory()->create(['user_group_id' => $group->id]);

        [$ventaCartStore] = $this->storeIds('ventacart');
        [$opencartStore] = $this->storeIds('opencart');

        $this->actingAs($user)->get('/channels/ventacart/'.$ventaCartStore)->assertOk();
        $this->actingAs($user)->get('/channels/opencart/'.$opencartStore)->assertOk();
        $this->actingAs($user)->get('/channels/shopee')->assertOk();

        foreach ($this->keysThatMustNotResolve($ventaCartStore, $opencartStore) as $name => $key) {
            $this->actingAs($user)
                ->get('/channels/'.ChannelWorkspace::pathKey($key))
                ->assertNotFound('Expected '.$name.' ('.$key.') to be not found.');
        }
    }

    private function pagesFor(string $channel, int $storeId): array
    {
        return [
            'orders' => '/channels/'.$channel.'/'.$storeId.'/orders',
            'product groups' => '/channels/'.$channel.'/'.$storeId.'/product-groups',
            'settings' => '/channels/'.$channel.'/'.$storeId.'/settings',
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
                $response->assertSee('x-chnav', false);
                $response->assertSee('All channels');
                preg_match('/<div class="x-topbar__crumb">(.*?)<\/div>/s', $response->getContent(), $mbCrumb);
                $this->assertStringContainsString(e($store->label), $mbCrumb[1] ?? '',
                    'The trail does not name the store on the '.$name.' page.');
                $response->assertSee('data-nav', false);
            }
        }
    }

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

            $this->assertSame(1, substr_count($crumb, \App\Support\Breadcrumbs::CHANNEL_ROOT), 'Trail repeats on '.$path.': '.$flat);
            $this->assertSame(1, substr_count($crumb, $card->name), 'Channel repeats on '.$path.': '.$flat);
            $this->assertSame(1, substr_count($crumb, $store->label), 'Store missing or repeated on '.$path.': '.$flat);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_an_orders_only_operator_is_refused_the_store_workspace(string $channel): void
    {
        [$first] = $this->storeIds($channel);
        $user = $this->ordersOnlyOperator($channel);

        $this->assertTrue($user->hasPermission('view_'.$channel.'/order'));
        $this->assertFalse($user->hasPermission('view_'.$channel.'/dashboard'));

        $this->actingAs($user)->get('/channels/'.$channel.'/'.$first)->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_an_orders_only_operator_reaches_orders_with_a_narrow_sidebar(string $channel): void
    {
        [$first] = $this->storeIds($channel);

        $response = $this->actingAs($this->ordersOnlyOperator($channel))
            ->get('/channels/'.$channel.'/'.$first.'/orders');

        $response->assertOk();
        $response->assertSee('x-chnav', false);

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringContainsString('>Orders</a>', $chrome);
        $this->assertStringContainsString(route('ext.'.$channel.'.orders.index', ['store' => $first]), $chrome);

        $this->assertStringNotContainsString('data-chnav-group="Catalog"', $chrome);
        $this->assertStringNotContainsString('data-chnav-group="Settings"', $chrome);
        $this->assertStringNotContainsString(route('ext.'.$channel.'.product-groups.index', ['store' => $first]), $chrome);
        $this->assertStringNotContainsString(route('ext.'.$channel.'.settings.show', ['store' => $first]), $chrome);
        $this->assertStringNotContainsString(
            $this->overviewHref($channel.':'.$first),
            $chrome
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_a_permitted_operator_gets_the_overview_link_for_this_store(string $channel): void
    {
        [$first, $second] = $this->storeIds($channel);

        $response = $this->actingAs($this->operator($channel))
            ->get('/channels/'.$channel.'/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringContainsString(
            $this->overviewHref($channel.':'.$first),
            $chrome
        );
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

        $this->actingAs($user)->get('/channels/'.$channel.'/'.$first)->assertNotFound();

        $this->actingAs($user)->get('/channels/'.$channel.'/'.$first.'/orders')->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_channels_nav_offers_one_seller_center_per_store(string $channel): void
    {
        $card = $this->card($channel);

        $this->actingAs($this->operator($channel));

        $channels = collect(\App\Support\Navigation::groups())->firstWhere('label', 'Channels');

        $this->assertNotNull($channels, 'The nav has no Channels group.');

        $channelEntry = collect($channels['items'])->firstWhere('label', $card->name);
        $this->assertNotNull($channelEntry, 'The nav has no entry for '.$card->name);
        $this->assertArrayNotHasKey('route', $channelEntry, 'the channel line is a sub-group, not a link');
        foreach ($card->stores as $store) {
            $label = $store->label;
            $entry = collect($channelEntry['children'])->firstWhere('label', $label);

            $this->assertNotNull($entry, 'The nav has no entry for '.$label);
            $this->assertSame('ext.'.$card->id.'.dashboard', $entry['route']);
            $this->assertSame(
                ChannelWorkspace::overviewUrl($store->id),
                route($entry['route'], $entry['params'])
            );
        }

        $this->assertArrayNotHasKey('route', collect($channels['items'])->firstWhere('label', $card->name));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_board_opens_each_store_in_its_own_workspace(string $channel): void
    {
        $card = $this->card($channel);

        $response = $this->actingAs($this->operator($channel))->get('/channels');

        $response->assertOk();

        foreach ($card->stores as $store) {
            $response->assertSee($this->overviewHref($store->id), false);
        }

        $response->assertDontSee($this->overviewHref($channel), false);
    }

    public function test_the_board_gives_each_store_a_products_link_to_its_own_listings(): void
    {
        $card = $this->card('ventacart');
        $response = $this->actingAs($this->operator('ventacart'))->get('/channels')->assertOk();
        $links = collect($response->viewData('channels'))->pluck('productsUrl')->filter()->values()->all();

        $this->assertNotEmpty($card->stores);
        foreach ($card->stores as $store) {
            $url = route('ext.ventacart.listings.index', ['store' => (int) (explode(':', (string) $store->id, 2)[1] ?? 0)]);
            $this->assertContains($url, $links, 'each store links to its own Listings page');
            $response->assertSee('href="' . e($url) . '"', false);
        }
    }

    public function test_a_channel_without_a_listings_page_gets_no_products_link(): void
    {
        $rows = collect($this->actingAs($this->operator('opencart'))->get('/channels')->assertOk()->viewData('channels'))
            ->filter(fn (array $row) => str_starts_with((string) $row['key'], 'opencart'));

        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn (array $row) => $row['productsUrl'] === null), 'an OpenCart store has no Listings page to link');
    }

    private function ordersManager(string $channel): User
    {
        $group = UserGroup::create(['name' => 'Multistore '.$channel.' Order Managers']);

        $group->permissions()->attach(
            Permission::whereIn('key', ['view_'.$channel.'/settings', 'manage_'.$channel.'/order', 'manage_api/order', 'manage_sales/order'])
                ->pluck('id')
                ->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function ventaCartOrder(int $storeId, string $customer, string $status): \Extensions\ventacart\Models\VentaCartOrder
    {
        return \Extensions\ventacart\Models\VentaCartOrder::create([
            'ventacart_setting_id'   => $storeId,
            'ventacart_order_id'     => ($storeId * 100000) + random_int(1, 99999),
            'ventacart_order_number' => ($storeId * 100000) + random_int(1, 99999),
            'status'             => $status,
            'status_id'          => 1,
            'customer_name'      => $customer,
            'total'              => 1234.50,
            'payment_method'     => 'Cash on delivery',
            'order_created_at'   => now()->subDay(),
        ]);
    }

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

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_orders_page_renders_the_shared_panel_per_store(string $channel): void
    {
        $user = $this->ordersManager($channel);

        foreach ($this->storeIds($channel) as $storeId) {
            $response = $this->actingAs($user)->get('/channels/'.$channel.'/'.$storeId.'/orders');

            $response->assertOk();
            $response->assertSee('id="'.$channel.'-orders-page"', false);
            $response->assertSee(
                '<form method="GET" action="'.e(route('ext.'.$channel.'.orders.index', ['store' => $storeId])).'" class="x-filters" data-desk-tool id="orders-filter">',
                false
            );
            $response->assertDontSee('<input type="hidden" name="channel"', false);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channelProvider')]
    public function test_the_orders_page_no_longer_carries_the_cross_marketplace_strip(string $channel): void
    {
        [$first] = $this->storeIds($channel);

        $response = $this->actingAs($this->operator($channel))
            ->get('/channels/'.$channel.'/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringNotContainsString('ord-tab-strip', $chrome);
        $this->assertStringNotContainsString('ord-tab-card', $chrome);
        $this->assertStringNotContainsString(
            'href="'.e(route('channels.fulfilment')).'"',
            $chrome
        );
    }

    public function test_a_ventacart_stores_orders_page_holds_none_of_the_other_stores_orders(): void
    {
        [$first, $second] = $this->storeIds('ventacart');

        $mine = $this->ventaCartOrder($first, 'Depot Buyer', 'Processing');
        $theirs = $this->ventaCartOrder($second, 'Bazaar Buyer', 'Processing');

        $response = $this->actingAs($this->ordersManager('ventacart'))
            ->get('/channels/ventacart/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringContainsString('Depot Buyer', $chrome);
        $this->assertStringContainsString((string) $mine->ventacart_order_number, $chrome);

        $this->assertStringNotContainsString('Bazaar Buyer', $chrome);
        $this->assertStringNotContainsString((string) $theirs->ventacart_order_number, $chrome);
        $this->assertStringNotContainsString(route('ext.ventacart.orders.fetch', ['store' => $second]), $chrome);
        $this->assertStringNotContainsString(route('ext.ventacart.orders.bulk_delete', ['store' => $second]), $chrome);
    }

    public function test_an_opencart_stores_orders_page_holds_none_of_the_other_stores_orders(): void
    {
        [$first, $second] = $this->storeIds('opencart');

        $mineId = $this->opencartOrder($first, 'Yardley', 'OC-YARD-1');
        $theirsId = $this->opencartOrder($second, 'Loftus', 'OC-LOFT-1');

        $response = $this->actingAs($this->ordersManager('opencart'))
            ->get('/channels/opencart/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());

        $this->assertStringContainsString('Yardley', $chrome);
        $this->assertStringContainsString('OC-YARD-1', $chrome);

        $this->assertStringNotContainsString('Loftus', $chrome);
        $this->assertStringNotContainsString('OC-LOFT-1', $chrome);
        $this->assertStringContainsString('href="'.e(route('orders.show', $mineId)).'"', $chrome);
        $this->assertStringNotContainsString('href="'.e(route('orders.show', $theirsId)).'"', $chrome);
    }

    public function test_only_the_manage_tier_gets_the_ventacart_write_controls(): void
    {
        [$first] = $this->storeIds('ventacart');
        $this->ventaCartOrder($first, 'Depot Buyer', 'Processing');

        $manager = $this->ordersManager('ventacart');
        $viewer = $this->operator('ventacart');

        $this->assertTrue($manager->hasPermission('manage_ventacart/order'));
        $this->assertFalse($viewer->hasPermission('manage_ventacart/order'));

        $managerView = $this->actingAs($manager)->get('/channels/ventacart/'.$first.'/orders');
        $managerView->assertOk();
        $managerView->assertSee('id="formFetchVentaOrders"', false);
        $managerView->assertSee(e(route('ext.ventacart.orders.fetch', ['store' => $first])), false);
        $managerView->assertSee(e(route('ext.ventacart.orders.bulk_delete', ['store' => $first])), false);
        $managerView->assertSee('data-confirm=', false);

        $viewerView = $this->actingAs($viewer)->get('/channels/ventacart/'.$first.'/orders');
        $viewerView->assertOk();
        $viewerView->assertDontSee('id="formFetchVentaOrders"', false);
        $viewerView->assertDontSee(e(route('ext.ventacart.orders.bulk_delete', ['store' => $first])), false);
    }

    public function test_the_opencart_orders_page_has_no_command_bar_and_core_gated_row_actions(): void
    {
        [$first] = $this->storeIds('opencart');
        $orderId = $this->opencartOrder($first, 'Yardley', 'OC-YARD-1');

        $manager = $this->ordersManager('opencart');
        $viewer = $this->operator('opencart');

        $managerView = $this->actingAs($manager)->get('/channels/opencart/'.$first.'/orders');
        $managerView->assertOk();
        $managerView->assertDontSee('x-cmdbar', false);
        $managerView->assertSee('href="'.e(route('orders.edit', $orderId)).'"', false);
        $managerView->assertSee('data-confirm=', false);

        $viewerView = $this->actingAs($viewer)->get('/channels/opencart/'.$first.'/orders');
        $viewerView->assertOk();
        $viewerView->assertDontSee('x-cmdbar', false);
        $viewerView->assertDontSee('href="'.e(route('orders.edit', $orderId)).'"', false);
    }

    public function test_the_ventacart_panels_own_links_stay_on_this_store(): void
    {
        [$first, $second] = $this->storeIds('ventacart');
        $this->ventaCartOrder($first, 'Depot Buyer', 'Processing');

        $response = $this->actingAs($this->ordersManager('ventacart'))
            ->get('/channels/ventacart/'.$first.'/orders');

        $response->assertOk();

        $chrome = $this->visibleChrome($response->getContent());
        $own = route('ext.ventacart.orders.index', ['store' => $first]);
        $sibling = route('ext.ventacart.orders.index', ['store' => $second]);

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(e($own), '/').'\?[^"]*tab=/',
            $chrome,
            'A tab segment link did not stay on this store.'
        );
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(e($own), '/').'\?[^"]*sort=total/',
            $chrome,
            'A column sort link did not stay on this store.'
        );

        $this->assertStringNotContainsString($sibling, $chrome);
    }
}
