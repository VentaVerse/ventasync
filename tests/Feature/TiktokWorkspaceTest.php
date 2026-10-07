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
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\TiktokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TiktokWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private TikTokSetting $tiktokStore;

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

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');

        $this->app->register(TiktokExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->tiktokStore = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
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

    private function tiktokMenu(): array
    {
        $stores = $this->tiktokCard()->stores;
        $this->assertNotEmpty($stores, 'TikTok card exposed no store link to read a menu from.');

        return $stores[0]->menu;
    }

    public function test_tiktok_menu_covers_every_workspace_destination(): void
    {
        $routes = array_map(
            fn ($i) => $i->routeName,
            $this->tiktokMenu()
        );

        $this->assertEqualsCanonicalizing([
            'ext.tiktok.orders.index',
            'ext.tiktok.orders.returns',
            'ext.tiktok.coupons.index',
            'ext.tiktok.products.index',
            'ext.tiktok.products.import',
            'ext.tiktok.product-groups.index',
            'ext.tiktok.description-templates.index',
            'ext.tiktok.watermarks.index',
            'ext.tiktok.categories.index',
            'ext.tiktok.index',
        ], $routes);
    }

    public function test_every_tiktok_menu_item_declares_a_known_group(): void
    {
        foreach ($this->tiktokMenu() as $item) {
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
        $groups = app(ChannelWorkspace::class)->groups($this->tiktokCard(), $user, 'tiktok:'.$this->tiktokStore->id);

        $this->assertSame(
            ['Sell', 'Catalog', 'Channel'],
            array_column($groups, 'label')
        );
        $this->assertSame(
            ['Orders', 'Returns'],
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
        $groups = app(ChannelWorkspace::class)->groups($this->tiktokCard(), $user, 'tiktok:'.$this->tiktokStore->id);

        $urls = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $this->assertIsString($item['url']);
                $this->assertStringStartsWith('http', $item['url']);
                $urls[] = $item['url'];
            }
        }

        $this->assertCount(5, $urls);
    }

    public function test_workspace_renders_for_a_permitted_user(): void
    {
        $user = $this->tiktokOperator();

        $this->actingAs($user)
            ->get('/channels/tiktok/'.$this->tiktokStore->id)
            ->assertOk()
            ->assertSee('TikTok')
            ->assertSee('Overview');
    }

    public function test_an_orders_only_operator_reaches_the_orders_page(): void
    {
        $user = $this->tiktokOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders');

        $response->assertOk();
        $response->assertSee('x-chnav', false);
    }

    public function test_an_orders_only_operator_sees_only_the_sell_group(): void
    {
        $user = $this->tiktokOrdersOnlyOperator();

        $response = $this->actingAs($user)->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders');

        $response->assertOk();

        $response->assertSee('data-chnav-group="Sell"', false);
        $response->assertSee('>Orders</a>', false);

        $response->assertDontSee('data-chnav-group="Catalog"', false);
        $response->assertDontSee('data-chnav-group="Channel"', false);
        $response->assertDontSee('>Product Groups</a>', false);
        $response->assertDontSee('>Categories</a>', false);
        $response->assertDontSee('>Settings</a>', false);
    }

    public static function tiktokPageProvider(): array
    {
        return [
            'orders' => ['orders'],
            'product groups' => ['product-groups'],
            'categories' => ['categories'],
            'settings' => ['settings'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tiktokPageProvider')]
    public function test_tiktok_pages_render_inside_the_workspace(string $suffix): void
    {
        $path = '/channels/tiktok/'.$this->tiktokStore->id.'/'.$suffix;

        $user = $this->tiktokOperator();

        $response = $this->actingAs($user)->get($path);

        $response->assertOk();
        $response->assertSee('x-chnav', false);
        $response->assertSee('All channels');
        $response->assertSee('data-nav', false);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tiktokPageProvider')]
    public function test_the_breadcrumb_trail_is_not_duplicated(string $suffix): void
    {
        $path = '/channels/tiktok/'.$this->tiktokStore->id.'/'.$suffix;

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

    public function test_the_seller_center_shows_no_other_marketplace(): void
    {
        $response = $this->actingAs($this->tiktokOperator())->get('/channels/tiktok/'.$this->tiktokStore->id.'/categories');

        $response->assertOk();
        $response->assertDontSee('All marketplaces');
        $response->assertDontSee('ord-tab-head', false);
    }

    public function test_the_orders_page_shows_no_other_marketplace(): void
    {
        $response = $this->actingAs($this->tiktokOperator())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders');

        $response->assertOk();
        $response->assertDontSee('All marketplaces');
        $response->assertDontSee('ord-tab-head', false);
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

        $tiktok = collect(collect($channels['items'])->firstWhere('label', 'TikTok Shop')['children'])->firstWhere('label', 'Main store');
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
            ->assertSee('href="'.e(ChannelWorkspace::overviewUrl('tiktok')).'"', false);
    }

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

        $response = $this->actingAs($this->tiktokManager())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee('id="btnFetchOrders"', false);
        $response->assertSee('id="btnUpdateOrders"', false);

        $response->assertSee(
            'action="' . e(route('ext.tiktok.orders.ship', $ids['AWAITING_SHIPMENT'])) . '"',
            false
        );
        $response->assertSee('btnTtShip', false);
        $response->assertSee('data-parcel-for="' . $ids['AWAITING_SHIPMENT'] . '"', false);
        $response->assertSee('id="ttShipModal"', false);
        $response->assertSee('TikTok is told the parcel has left, and that cannot be undone from here.', false);
        $response->assertDontSee('data-confirm="Ship this order?"', false);
    }

    public function test_a_viewer_sees_neither_the_command_bar_nor_the_ship_control(): void
    {
        $ids = $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokOrdersOnlyOperator())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee('To pack');

        $response->assertDontSee('id="btnFetchOrders"', false);
        $response->assertDontSee('id="btnUpdateOrders"', false);
        $response->assertDontSee(
            'action="' . e(route('ext.tiktok.orders.ship', $ids['AWAITING_SHIPMENT'])) . '"',
            false
        );
        $response->assertDontSee('data-order-no=', false);
        $response->assertDontSee('data-parcel-for=', false);
    }

    public function test_a_viewer_keeps_the_waybill_and_tracking_controls(): void
    {
        $ids = $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokOrdersOnlyOperator())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee(e(route('ext.tiktok.orders.awb', $ids['AWAITING_COLLECTION'])), false);
        $response->assertSee(e(route('ext.tiktok.orders.tracking', $ids['IN_TRANSIT'])), false);
        $response->assertSee(e(route('ext.tiktok.orders.awb', [$ids['AWAITING_COLLECTION'], 'refresh' => 1])), false);
        $response->assertDontSee(e(route('ext.tiktok.orders.awb', [$ids['IN_TRANSIT'], 'refresh' => 1])), false);
    }

    public function test_unpaid_and_cancelled_orders_offer_no_actions(): void
    {
        $ids = $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokManager())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders?tab=ALL');

        $response->assertOk();
        foreach (['UNPAID', 'CANCELLED'] as $status) {
            $response->assertDontSee(e(route('ext.tiktok.orders.awb', $ids[$status])), false);
            $response->assertDontSee(e(route('ext.tiktok.orders.tracking', $ids[$status])), false);
            $response->assertDontSee(e(route('ext.tiktok.orders.ship', $ids[$status])), false);
        }
    }

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
            $this->assertNotSame($status, \App\Support\ChannelStatusTone::labelFor('tiktok.orders', $status));
        }

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

    public function test_the_status_column_shows_plain_labels(): void
    {
        $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokManager())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee('To pack');
        $response->assertSee('To hand over');
        $response->assertDontSee('AWAITING_SHIPMENT');
        $response->assertDontSee('AWAITING SHIPMENT');
        $response->assertDontSee('AWAITING_COLLECTION');
    }

    public function test_the_to_ship_sub_steps_filter_to_one_status_each(): void
    {
        $this->seedOrderPerStatus();

        $packNumber = '576000000000001';
        $handoverNumber = '576000000000002';

        $manager = $this->tiktokManager();

        $toPack = $this->actingAs($manager)->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders?tab=TO_SHIP&pending_sub=to_pack');
        $toPack->assertOk();
        $toPack->assertSee($packNumber);
        $toPack->assertDontSee($handoverNumber);

        $toHandover = $this->actingAs($manager)->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders?tab=TO_SHIP&pending_sub=to_handover');
        $toHandover->assertOk();
        $toHandover->assertSee($handoverNumber);
        $toHandover->assertDontSee($packNumber);
    }

    public function test_the_order_number_opens_the_detail_page_in_place(): void
    {
        $ids = $this->seedOrderPerStatus();

        $response = $this->actingAs($this->tiktokManager())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee(
            '<a class="co-sn" href="' . e(route('ext.tiktok.orders.show', $ids['COMPLETED'])) . '">',
            false
        );
        $response->assertDontSee('target="_blank" rel="noopener">' . $ids['COMPLETED'], false);
    }
}
