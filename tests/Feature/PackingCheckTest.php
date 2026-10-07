<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Setting;
use App\Models\User;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\ventacart\Models\VentaCartOrder;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PackingCheckTest extends TestCase
{
    use RefreshDatabase;

    private LazadaSetting $lazada;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['lazada' => \Extensions\lazada\LazadaExtension::class,
            'shopee' => \Extensions\shopee\ShopeeExtension::class,
            'tiktok' => \Extensions\tiktok\TiktokExtension::class,
            'ventacart' => \Extensions\ventacart\VentaCartExtension::class] as $id => $provider) {
            $manager->install($id);
            $manager->enable($id);
            $this->app->register($provider);
        }
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        $this->lazada = LazadaSetting::query()->create(['store_name' => 'Gearshipper', 'enabled' => true, 'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't', 'refresh_token' => 'r']);
        Setting::singleton()->update(['packing_check' => true]);
        Cache::flush();
    }

    private function packer(array $keys = ['manage_lazada/order', 'manage_shopee/order', 'manage_tiktok/order', 'manage_ventacart/order']): User
    {
        $group = UserGroup::create(['name' => 'Packers ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function lazadaOrder(string $orderId = '1105065607396146'): LazadaOrder
    {
        $order = LazadaOrder::query()->create(['lazada_setting_id' => $this->lazada->id, 'region' => 'ph', 'order_id' => $orderId,
            'status' => 'pending', 'order_created_at' => now(), 'raw' => ['order_id' => $orderId]]);
        foreach ([['QB-TS50', 'Qable TS50 Guitar Cable', 'Cable Length:10 Ft', 1], ['QB-TS50', 'Qable TS50 Guitar Cable', 'Cable Length:10 Ft', 1], ['PEN-1', 'Ballpen', '', 4]] as $i => [$sku, $name, $var, $qty]) {
            $order->products()->create(['order_item_id' => $orderId . $i, 'sku' => $sku, 'name' => $name, 'variation' => $var, 'quantity' => $qty, 'item_price' => 10, 'paid_price' => 10, 'status' => 'pending']);
        }

        return $order;
    }

    public function test_the_lines_never_carry_a_quantity(): void
    {
        $order = $this->lazadaOrder();

        $res = $this->actingAs($this->packer())->getJson(route('fulfilment.packing_check.show', ['channel' => 'lazada', 'order' => $order->id]))
            ->assertOk()
            ->assertJsonPath('reference', '1105065607396146');

        $this->assertSame(['Qable TS50 Guitar Cable', 'Ballpen'], array_column($res->json('lines'), 'name'), 'one line per product, the split rows joined');
        $this->assertSame('Cable Length:10 Ft', $res->json('lines.0.variation'));
        foreach ($res->json('lines') as $line) {
            $this->assertArrayNotHasKey('quantity', $line);
        }
        $this->assertStringNotContainsString('"quantity"', $res->getContent());
    }

    public function test_a_wrong_count_books_nothing_and_a_right_one_lets_that_person_book(): void
    {
        $order = $this->lazadaOrder();
        $packer = $this->packer();
        $verify = route('fulfilment.packing_check.verify', ['channel' => 'lazada', 'order' => $order->id]);

        foreach ([['l0' => '2', 'l1' => '3'], ['l0' => '4', 'l1' => '2'], ['l0' => '2'], ['l0' => '2', 'l1' => '4.0'], ['l0' => ' ', 'l1' => '4']] as $counts) {
            $this->actingAs($packer)->postJson($verify, ['counts' => $counts])
                ->assertStatus(422)
                ->assertJsonPath('message', 'That count is wrong. Count again.');
        }

        $this->actingAs($packer)
            ->post(route('ext.lazada.orders.pack_print', ['store' => $this->lazada->id, 'orderId' => '1105065607396146']))
            ->assertRedirect()
            ->assertSessionHas('lazada_orders_last_result', fn ($r) => $r['ok'] === false && $r['message'] === 'Count the items before booking this order.');

        $this->actingAs($packer)->postJson($verify, ['counts' => ['l0' => '2', 'l1' => '4']])->assertOk()->assertJsonPath('ok', true);

        $this->actingAs($packer)
            ->post(route('ext.lazada.orders.pack_print', ['store' => $this->lazada->id, 'orderId' => '1105065607396146']))
            ->assertSessionMissing('error');

        $this->actingAs($this->packer())
            ->post(route('ext.lazada.orders.pack_print', ['store' => $this->lazada->id, 'orderId' => '1105065607396146']))
            ->assertSessionHas('error', 'Count the items before booking this order.');
    }

    public function test_a_bulk_booking_needs_every_selected_order_counted(): void
    {
        $a = $this->lazadaOrder('A-1');
        $b = $this->lazadaOrder('B-1');
        $packer = $this->packer();

        $this->actingAs($packer)->postJson(route('fulfilment.packing_check.verify', ['channel' => 'lazada', 'order' => $a->id]), ['counts' => ['l0' => '2', 'l1' => '4']])->assertOk();

        $this->actingAs($packer)
            ->post(route('ext.lazada.orders.bulk_pack_print', ['store' => $this->lazada->id]), ['ids' => [$a->id, $b->id]])
            ->assertSessionHas('error', 'Count the items before booking this order.');
    }

    private function standInBooking(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'packing.check:lazada'])
            ->post('/_test/lazada/book', fn (\Illuminate\Http\Request $r) => match ($r->input('answer')) {
                'json-ok' => response()->json(['ok' => true]),
                'json-refused' => response()->json(['ok' => false, 'message' => 'No courier.'], 422),
                'json-already' => response()->json(['ok' => false, 'error' => 'already_done'], 409),
                'flash-ok' => back()->with('lazada_orders_last_result', ['ok' => true, 'message' => 'Packed.']),
                'flash-refused' => back()->with('lazada_orders_last_result', ['ok' => false, 'message' => 'No courier.']),
                'bulk' => back()->with('lazada_bulk_pack', ['packed_ids' => [(int) $r->input('ids.0')]]),
                default => back(),
            });
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function countRight(User $packer, LazadaOrder $order): void
    {
        $this->actingAs($packer)
            ->postJson(route('fulfilment.packing_check.verify', ['channel' => 'lazada', 'order' => $order->id]), ['counts' => ['l0' => '2', 'l1' => '4']])
            ->assertOk();
    }

    private function counted(User $packer, LazadaOrder $order): bool
    {
        return \App\Support\Fulfilment\PackingCheck::passed($packer, 'lazada', $order->id);
    }

    public function test_one_count_books_once(): void
    {
        $this->standInBooking();
        $order = $this->lazadaOrder();
        $packer = $this->packer();
        $book = fn (string $answer) => $this->actingAs($packer)->post('/_test/lazada/book', ['ids' => [$order->id], 'answer' => $answer]);

        foreach (['json-refused', 'flash-refused', 'nothing'] as $answer) {
            $this->countRight($packer, $order);
            $book($answer);
            $this->assertTrue($this->counted($packer, $order), $answer . ': a refused booking keeps the count for a retry');
        }

        foreach (['json-ok', 'json-already', 'flash-ok'] as $answer) {
            $this->countRight($packer, $order);
            $book($answer);
            $this->assertFalse($this->counted($packer, $order), $answer . ': a booking spends the count');
            $book($answer)->assertSessionHas('error', 'Count the items before booking this order.');
        }
    }

    public function test_a_stale_flash_does_not_speak_for_a_booking(): void
    {
        $this->standInBooking();
        $order = $this->lazadaOrder();
        $packer = $this->packer();
        $this->countRight($packer, $order);

        $this->actingAs($packer)
            ->withSession(['lazada_orders_last_result' => ['ok' => true], '_flash' => ['old' => ['lazada_orders_last_result'], 'new' => []]])
            ->post('/_test/lazada/book', ['ids' => [$order->id], 'answer' => 'nothing']);

        $this->assertTrue($this->counted($packer, $order));
    }

    public function test_a_bulk_pack_spends_only_the_orders_it_packed(): void
    {
        $this->standInBooking();
        $a = $this->lazadaOrder('A-1');
        $b = $this->lazadaOrder('B-1');
        $packer = $this->packer();
        $this->countRight($packer, $a);
        $this->countRight($packer, $b);

        $this->actingAs($packer)->post('/_test/lazada/book', ['ids' => [$a->id, $b->id], 'answer' => 'bulk']);

        $this->assertFalse($this->counted($packer, $a), 'packed');
        $this->assertTrue($this->counted($packer, $b), 'not packed, so its count still stands');
    }

    public function test_every_booking_route_on_every_channel_waits_for_the_count(): void
    {
        $packer = $this->packer();

        $shopee = ShopeeSetting::query()->create(['store_name' => 'Main store', 'enabled' => true, 'partner_id' => 1, 'partner_key' => 'k', 'shop_id' => 2, 'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production']);
        ShopeeOrder::create(['shopee_setting_id' => $shopee->id, 'region' => 'ph', 'order_sn' => 'SP-1', 'status' => 'READY_TO_SHIP', 'order_created_at' => now(), 'raw' => []]);
        $this->actingAs($packer)->postJson(route('ext.shopee.orders.ship', ['store' => $shopee->id, 'orderSn' => 'SP-1']), ['type' => 'dropoff'])
            ->assertStatus(422)->assertJsonPath('message', 'Count the items before booking this order.');

        $tiktok = TikTokSetting::create(['store_name' => 'Gearshipper', 'enabled' => true, 'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c']);
        $tt = TikTokOrder::create(['tiktok_setting_id' => $tiktok->id, 'order_id' => 'TT-1', 'status' => 'AWAITING_SHIPMENT', 'order_created_at' => now(), 'raw' => []]);
        $this->actingAs($packer)->post(route('ext.tiktok.orders.ship', ['store' => $tiktok->id, 'id' => $tt->id]))
            ->assertSessionHas('error', 'Count the items before booking this order.');

        $ventacart = VentaCartSetting::query()->create(['store_name' => 'New Gear Day', 'enabled' => true, 'base_url' => 'https://shop.test', 'api_token' => 'x']);
        $vo = VentaCartOrder::query()->create(['ventacart_setting_id' => $ventacart->id, 'ventacart_order_id' => 77, 'ventacart_order_number' => 77, 'status' => 'processing', 'raw' => []]);
        foreach (['ext.ventacart.orders.book', 'ext.ventacart.orders.book_manual'] as $name) {
            $this->actingAs($packer)->postJson(route($name, ['store' => $ventacart->id, 'order' => $vo->id]), [])
                ->assertStatus(422)->assertJsonPath('message', 'Count the items before booking this order.');
        }

        $this->actingAs($packer)->post(route('ext.lazada.orders.pack', ['store' => $this->lazada->id, 'orderId' => $this->lazadaOrder('P-1')->order_id]))
            ->assertSessionHas('error', 'Count the items before booking this order.');
    }

    public function test_switched_off_nothing_changes(): void
    {
        Setting::singleton()->update(['packing_check' => false]);
        $order = $this->lazadaOrder();

        $this->actingAs($this->packer())->getJson(route('fulfilment.packing_check.show', ['channel' => 'lazada', 'order' => $order->id]))->assertNotFound();
        $this->actingAs($this->packer())
            ->post(route('ext.lazada.orders.pack_print', ['store' => $this->lazada->id, 'orderId' => '1105065607396146']))
            ->assertSessionMissing('error');
    }

    public function test_only_someone_who_may_book_the_channel_can_ask(): void
    {
        $order = $this->lazadaOrder();

        $this->actingAs($this->packer(['manage_shopee/order']))
            ->getJson(route('fulfilment.packing_check.show', ['channel' => 'lazada', 'order' => $order->id]))->assertForbidden();
        $this->actingAs($this->packer(['manage_shopee/order']))
            ->postJson(route('fulfilment.packing_check.verify', ['channel' => 'lazada', 'order' => $order->id]), ['counts' => ['l0' => '2', 'l1' => '4']])->assertForbidden();
    }

    public function test_the_switch_lives_on_settings_fulfilment(): void
    {
        Setting::singleton()->update(['packing_check' => false]);
        $admin = $this->packer(['manage_settings/fulfilment', 'view_settings/settings_hub']);

        $this->actingAs($admin)->get(route('settings.hub'))->assertOk()->assertSee(route('settings.fulfilment'), false);
        $this->actingAs($admin)->get(route('settings.fulfilment'))->assertOk()->assertSee('Count every item before booking');

        $this->actingAs($admin)->put(route('settings.fulfilment.update'), ['packing_check' => '1'])
            ->assertRedirect(route('settings.fulfilment'))->assertSessionHas('status', 'Fulfilment settings saved.');
        $this->assertTrue((bool) Setting::singleton()->fresh()->packing_check);

        $this->actingAs($admin)->get(route('settings.fulfilment'))->assertSee('name="packing-check"', false);

        $this->actingAs($admin)->put(route('settings.fulfilment.update'), [])->assertRedirect();
        $this->assertFalse((bool) Setting::singleton()->fresh()->packing_check);

        $viewer = $this->packer(['view_settings/fulfilment']);
        $this->actingAs($viewer)->put(route('settings.fulfilment.update'), ['packing_check' => '1']);
        $this->assertFalse((bool) Setting::singleton()->fresh()->packing_check, 'looking is not changing');
    }

    public function test_every_booking_button_asks_for_the_count(): void
    {
        foreach ([
            'extensions/lazada/views/orders/_panel.blade.php' => ['data-pc-channel="lazada" data-pc-order', 'data-pc-channel="lazada" data-pc-bulk="ids[]"'],
            'extensions/shopee/views/orders/_panel.blade.php' => ['data-pc-channel="shopee" data-pc-order'],
            'extensions/shopee/views/orders/show.blade.php' => ['data-pc-channel="shopee" data-pc-order'],
            'extensions/tiktok/views/orders/_panel.blade.php' => ['data-pc-channel="tiktok" data-pc-order'],
            'extensions/ventacart/views/orders/_panel.blade.php' => ['data-pc-channel="ventacart" data-pc-order'],
        ] as $file => $needles) {
            $src = file_get_contents(base_path($file));
            $this->assertIsString($src, $file);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $src, "{$file} has a booking button that skips the count");
            }
        }
    }
}
