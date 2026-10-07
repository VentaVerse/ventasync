<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\ChannelWorkspace;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LazadaWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private LazadaSetting $lazadaStore;

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

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');

        $this->app->register(LazadaExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->lazadaStore = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
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

    private function lazadaMenu(): array
    {
        $stores = $this->lazadaCard()->stores;
        $this->assertNotEmpty($stores, 'Lazada card exposed no store link to read a menu from.');

        return $stores[0]->menu;
    }

    public function test_lazada_menu_covers_every_workspace_destination(): void
    {
        $routes = array_map(
            fn ($i) => $i->routeName,
            $this->lazadaMenu()
        );

        $this->assertEqualsCanonicalizing([
            'ext.lazada.orders.index',
            'ext.lazada.orders.returns',
            'ext.lazada.vouchers.index',
            'ext.lazada.products.index',
            'ext.lazada.products.import',
            'ext.lazada.product-groups.index',
            'ext.lazada.description-templates.index',
            'ext.lazada.watermarks.index',
            'ext.lazada.categories.index',
            'ext.lazada.brands.index',
            'ext.lazada.index',
        ], $routes);
    }

    public function test_every_lazada_menu_item_declares_a_known_group(): void
    {
        foreach ($this->lazadaMenu() as $item) {
            $this->assertContains(
                $item->group,
                ['Sell', 'Catalog', 'Channel'],
                "Menu item {$item->label} has group ".var_export($item->group, true)
            );
        }
    }

    public function test_lazada_orders_menu_item_keeps_its_default_tab_param(): void
    {
        foreach ($this->lazadaMenu() as $item) {
            if ($item->routeName === 'ext.lazada.orders.index') {
                $this->assertSame(['store' => $this->lazadaStore->id, 'tab' => 'TO_SHIP'], $item->routeParams);

                return;
            }
        }

        $this->fail('Orders menu item not found.');
    }

    public function test_groups_are_ordered_and_bucketed(): void
    {
        $user = $this->lazadaOperator();
        $groups = app(ChannelWorkspace::class)->groups($this->lazadaCard(), $user, 'lazada:'.$this->lazadaStore->id);

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
        $groups = app(ChannelWorkspace::class)->groups($this->lazadaCard(), $user, 'lazada:'.$this->lazadaStore->id);

        $urls = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $this->assertIsString($item['url']);
                $this->assertStringStartsWith('http', $item['url']);
                $urls[] = $item['url'];
            }
        }

        $this->assertCount(8, $urls);
    }

    public function test_workspace_renders_for_a_permitted_user(): void
    {
        $user = $this->lazadaOperator();

        $this->actingAs($user)
            ->get('/channels/lazada/'.$this->lazadaStore->id)
            ->assertOk()
            ->assertSee('Lazada')
            ->assertSee('Overview');
    }

    public function test_an_orders_only_operator_reaches_the_orders_page(): void
    {
        $user = $this->lazadaOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/channels/lazada/'.$this->lazadaStore->id.'/orders');

        $response->assertOk();
        $response->assertSee('x-chnav', false);
    }

    public function test_an_orders_only_operator_sees_only_the_sell_group(): void
    {
        $user = $this->lazadaOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/channels/lazada/'.$this->lazadaStore->id.'/orders');

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
            'orders' => ['orders'],
            'returns' => ['orders/returns'],
            'listings' => ['products'],
            'product groups' => ['product-groups'],
            'categories' => ['categories'],
            'brands' => ['brands'],
            'settings' => ['settings'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lazadaPageProvider')]
    public function test_lazada_pages_render_inside_the_workspace(string $suffix): void
    {
        $path = '/channels/lazada/'.$this->lazadaStore->id.'/'.$suffix;

        $user = $this->lazadaOperator();

        $response = $this->actingAs($user)->get($path);

        $response->assertOk();
        $response->assertSee('x-chnav', false);
        $response->assertSee('All channels');
        $response->assertSee('data-nav', false);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lazadaPageProvider')]
    public function test_the_breadcrumb_trail_is_not_duplicated(string $suffix): void
    {
        $path = '/channels/lazada/'.$this->lazadaStore->id.'/'.$suffix;

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

    public function test_the_seller_center_shows_no_other_marketplace(): void
    {
        $response = $this->actingAs($this->lazadaOperator())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/returns');

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

        $lazada = collect(collect($channels['items'])->firstWhere('label', 'Lazada')['children'])->firstWhere('label', 'Main store');
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
            ->assertSee('href="'.e(ChannelWorkspace::overviewUrl('lazada')).'"', false);
    }

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

    public function test_a_viewer_reaches_orders_and_is_offered_no_write_control(): void
    {
        $this->seedLazadaOrders();

        $response = $this->actingAs($this->lazadaOrdersOnlyOperator())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders?tab=ALL');

        $response->assertOk();
        $response->assertDontSee('x-cmdbar', false);
        $response->assertDontSee('Pack and print');
        $response->assertDontSee('Arrange shipment');
        $response->assertDontSee('Recreate package');
        $response->assertDontSee('id="lzPackPrintModal"', false);

        $response->assertSee('Packing list');
        $response->assertSee('Pick list');
    }

    public function test_the_manage_tier_is_offered_every_action_its_statuses_allow(): void
    {
        $this->seedLazadaOrders();

        $response = $this->actingAs($this->lazadaOrdersManager())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders?tab=ALL&per_page=50');

        $response->assertOk();
        $response->assertSee('formFetchLazadaOrders', false);
        $response->assertSee('id="btnUpdateOrders"', false);
        $response->assertSee('Pack and print');
        $response->assertSee('Arrange shipment');
        $response->assertSee('Recreate package');
        $response->assertSee('Print waybill');
        $response->assertSee('Tracking');
        $response->assertSee('data-confirm="Arrange shipment for this order?', false);
        $response->assertDontSee('confirm(', false);
    }

    public function test_the_write_routes_refuse_a_viewer_and_admit_the_manage_tier(): void
    {
        $order = $this->seedLazadaOrders()['packed'];

        $viewer = $this->lazadaOrdersOnlyOperator();

        $this->actingAs($viewer)
            ->postJson('/channels/lazada/'.$this->lazadaStore->id.'/orders/fetch', ['date_from' => '2026-08-01', 'date_to' => '2026-08-07'])
            ->assertForbidden()
            ->assertJsonPath('error', 'permission_denied');

        foreach ([
            '/channels/lazada/'.$this->lazadaStore->id.'/orders/fetch',
            '/channels/lazada/'.$this->lazadaStore->id.'/orders/update-statuses',
            '/channels/lazada/'.$this->lazadaStore->id.'/orders/returns/fetch',
            '/channels/lazada/'.$this->lazadaStore->id.'/orders/' . $order->order_id . '/rts',
            '/channels/lazada/'.$this->lazadaStore->id.'/orders/' . $order->order_id . '/pack-print',
            '/channels/lazada/'.$this->lazadaStore->id.'/orders/' . $order->order_id . '/recreate-package',
        ] as $writeRoute) {
            $this->actingAs($viewer)
                ->post($writeRoute, ['date_from' => '2026-08-01', 'date_to' => '2026-08-07'])
                ->assertRedirect()
                ->assertSessionHas('error', "You don't have permission to do this action.");
        }

        $this->actingAs($this->lazadaOrdersManager())
            ->post('/channels/lazada/'.$this->lazadaStore->id.'/orders/fetch', ['date_from' => '2026-08-01', 'date_to' => '2026-08-07'])
            ->assertRedirect()
            ->assertSessionHas('lazada_orders_last_result')
            ->assertSessionMissing('error');
    }

    public function test_a_viewer_reaches_returns_and_is_not_offered_the_fetch(): void
    {
        $viewer = $this->lazadaOrdersOnlyOperator();

        $this->actingAs($viewer)->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/returns')
            ->assertOk()
            ->assertDontSee('formFetchLazadaReturns', false)
            ->assertDontSee('Fetch returns');

        $this->actingAs($this->lazadaOrdersManager())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/returns')
            ->assertOk()
            ->assertSee('formFetchLazadaReturns', false)
            ->assertSee('Fetch returns');
    }

    public function test_lazada_statuses_render_as_plain_language_in_the_right_tone(): void
    {
        $this->seedLazadaOrders();

        $html = $this->actingAs($this->lazadaOrdersManager())
            ->get('/channels/lazada/'.$this->lazadaStore->id.'/orders?tab=ALL&per_page=50')
            ->getContent();

        $this->assertStringNotContainsString('>ready_to_ship<', $html);
        $this->assertStringNotContainsString('>failed_delivery<', $html);
        $this->assertStringNotContainsString('lost_by_3pl', $html);

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

        ksort($toneOf);

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

    public function test_the_rebuilt_orders_page_shows_no_other_marketplace(): void
    {
        $this->seedLazadaOrders();

        $response = $this->actingAs($this->lazadaOperator())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders');

        $response->assertOk();
        $response->assertDontSee('All marketplaces');
        $response->assertDontSee('ord-tab-head', false);
        $response->assertDontSee('Shopee');
        $response->assertDontSee('TikTok');
    }

    public function test_the_page_links_preserve_every_query_param_the_controller_reads(): void
    {
        $this->seedLazadaOrders();

        $html = $this->actingAs($this->lazadaOrdersManager())
            ->get('/channels/lazada/'.$this->lazadaStore->id.'/orders?tab=TO_SHIP&pending_sub=to_arrange&sort=created_asc&per_page=20&buyer_name=Fixture')
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/href="([^"]*pending_sub=to_handover[^"]*)"/', $html, $m),
            'The To Handover step link is missing from the sub-segment.'
        );
        $handoverLink = html_entity_decode($m[1]);
        foreach (['tab=TO_SHIP', 'pending_sub=to_handover', 'buyer_name=Fixture', 'sort=created_asc', 'per_page=20'] as $param) {
            $this->assertStringContainsString($param, $handoverLink, 'Switching step dropped ' . $param);
        }
        $this->assertStringNotContainsString('page=', str_replace('per_page=', '', $handoverLink));
        $this->assertStringContainsString('<input type="hidden" name="tab" value="TO_SHIP">', $html);
        $this->assertStringContainsString('<input type="hidden" name="pending_sub" value="to_arrange">', $html);
    }
}
