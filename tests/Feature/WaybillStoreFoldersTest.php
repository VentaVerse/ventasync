<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\Fulfilment\Waybills;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaybillStoreFoldersTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4 waybill fixture";

    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada', 'tiktok'] as $id) {
            $manager->install($id);
            $manager->enable($id);
        }
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app->register(\Extensions\tiktok\TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
            @rmdir(dirname($path));
        }

        parent::tearDown();
    }

    private function user(string $channel): User
    {
        $group = UserGroup::create(['name' => 'waybills ' . $channel]);
        $group->permissions()->attach(Permission::whereIn('key', ['view_' . $channel . '/order', 'manage_' . $channel . '/order'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function cache(string $channel, int $storeId, string $reference): void
    {
        $path = Waybills::path($channel, $storeId, $reference);
        Waybills::save($path, self::PDF);
        $this->written[] = $path;
    }

    public function test_the_path_names_the_channel_the_store_and_the_order(): void
    {
        $this->assertSame(storage_path('app/shopee-awb/2/2610039XYZ.pdf'), Waybills::path('shopee', 2, '2610039XYZ'));
        $this->assertSame('lazada-awb/3/1234.pdf', Waybills::relative('lazada', 3, '12/../34'));
    }

    public function test_shopee_serves_the_second_stores_waybill_from_its_folder(): void
    {
        ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $second = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $this->cache('shopee', $second->id, 'WAYBILLTEST01');

        $this->actingAs($this->user('shopee'))
            ->get(route('ext.shopee.orders.awb', ['store' => $second->id, 'orderSn' => 'WAYBILLTEST01']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_lazada_serves_the_second_stores_waybill_from_its_folder(): void
    {
        LazadaSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $second = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $this->cache('lazada', $second->id, '990000000001');

        $this->actingAs($this->user('lazada'))
            ->get(route('ext.lazada.orders.awb', ['store' => $second->id, 'orderId' => '990000000001']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_tiktok_serves_the_orders_own_stores_waybill(): void
    {
        TikTokSetting::create(['store_name' => 'Main store', 'mode' => 'production']);
        $second = TikTokSetting::create(['store_name' => 'Outlet', 'mode' => 'production']);
        $order = TikTokOrder::create(['tiktok_setting_id' => $second->id, 'order_id' => '880000000001', 'status' => 'AWAITING_COLLECTION']);
        $this->cache('tiktok', $second->id, '880000000001');

        $this->actingAs($this->user('tiktok'))
            ->get(route('ext.tiktok.orders.awb', ['store' => $second->id, 'id' => $order->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
