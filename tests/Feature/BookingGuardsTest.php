<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Http\Middleware\OneBookingAtATime;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BookingGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee' => \Extensions\shopee\ShopeeExtension::class,
            'lazada' => \Extensions\lazada\LazadaExtension::class,
            'tiktok' => \Extensions\tiktok\TiktokExtension::class] as $id => $provider) {
            $manager->install($id);
            $manager->enable($id);
            $this->app->register($provider);
        }
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        Http::preventStrayRequests();
    }

    private function packer(string $channel): User
    {
        $group = UserGroup::firstOrCreate(['name' => 'Packers ' . $channel]);
        $group->permissions()->detach();
        $group->permissions()->attach(Permission::whereIn('key', ['view_' . $channel . '/order', 'manage_' . $channel . '/order'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function shopeeOrder(string $status): array
    {
        $store = ShopeeSetting::create([
            'mode' => 'live', 'store_name' => 'Main store', 'region' => 'ph',
            'partner_id' => 1001, 'partner_key' => encrypt('key'), 'shop_id' => 2002, 'access_token' => encrypt('token'),
        ]);
        $order = ShopeeOrder::create(['shopee_setting_id' => $store->id, 'region' => 'ph', 'order_sn' => 'SN-GUARD-1', 'status' => $status, 'order_created_at' => now(), 'raw' => []]);

        return [$store, $order];
    }

    public function test_a_second_booking_of_the_same_order_is_turned_away(): void
    {
        [$store, $order] = $this->shopeeOrder('READY_TO_SHIP');
        $held = Cache::lock('fulfilment:shopee:' . $order->id, 120);
        $this->assertTrue($held->get());

        $this->actingAs($this->packer('shopee'))
            ->post(route('ext.shopee.orders.ship', ['store' => $store->id, 'orderSn' => 'SN-GUARD-1']))
            ->assertRedirect()
            ->assertSessionHas('shopee_orders_last_result', ['ok' => false, 'message' => OneBookingAtATime::BUSY]);

        $this->actingAs($this->packer('shopee'))
            ->postJson(route('ext.shopee.orders.ship', ['store' => $store->id, 'orderSn' => 'SN-GUARD-1']))
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'error' => 'busy']);

        $held->release();
    }

    public function test_a_shopee_order_already_arranged_is_not_sent_again_and_the_lock_is_let_go(): void
    {
        [$store, $order] = $this->shopeeOrder('PROCESSED');

        $this->actingAs($this->packer('shopee'))
            ->post(route('ext.shopee.orders.ship', ['store' => $store->id, 'orderSn' => 'SN-GUARD-1']))
            ->assertRedirect()
            ->assertSessionHas('shopee_orders_last_result', ['ok' => false, 'error' => 'already_done', 'message' => "This order's shipment is already arranged."]);

        $this->assertTrue(Cache::lock('fulfilment:shopee:' . $order->id, 1)->get(), 'the lock is let go once the request ends');
    }

    public function test_a_tiktok_order_already_shipped_is_not_sent_again(): void
    {
        $store = TikTokSetting::create(['store_name' => 'Main store', 'mode' => 'production']);
        $order = TikTokOrder::create(['tiktok_setting_id' => $store->id, 'order_id' => '770000000001', 'status' => 'AWAITING_COLLECTION', 'raw' => []]);

        $this->actingAs($this->packer('tiktok'))
            ->post(route('ext.tiktok.orders.ship', ['store' => $store->id, 'id' => $order->id]))
            ->assertRedirect()
            ->assertSessionHas('tiktok_orders_last_result', ['ok' => false, 'error' => 'already_done', 'message' => 'This order is already shipped.']);
    }

    public function test_a_lazada_order_already_ready_to_ship_is_not_sent_again(): void
    {
        $store = LazadaSetting::create([
            'mode' => 'live', 'store_name' => 'Main store', 'region' => 'ph',
            'app_key' => 'key', 'app_secret' => encrypt('secret'), 'access_token' => encrypt('token'),
        ]);
        LazadaOrder::create(['lazada_setting_id' => $store->id, 'region' => 'ph', 'order_id' => '660000000001', 'status' => 'ready_to_ship', 'order_created_at' => now(), 'raw' => []]);

        $this->actingAs($this->packer('lazada'))
            ->post(route('ext.lazada.orders.rts', ['store' => $store->id, 'orderId' => '660000000001']))
            ->assertRedirect()
            ->assertSessionHas('lazada_orders_last_result', ['ok' => false, 'error' => 'already_done', 'message' => 'This order is already ready to ship.']);

        $this->actingAs($this->packer('lazada'))
            ->post(route('ext.lazada.orders.ship_print_post', ['store' => $store->id, 'orderId' => '660000000001']))
            ->assertRedirect()
            ->assertSessionHas('lazada_orders_last_result', fn ($result) => $result['ok'] === false && $result['message'] === 'This order is already ready to ship.');
    }

    public function test_no_link_books_an_order(): void
    {
        $this->assertFalse(Route::has('ext.lazada.orders.ship_print'), 'a booking is a POST, never a link');
    }
}
