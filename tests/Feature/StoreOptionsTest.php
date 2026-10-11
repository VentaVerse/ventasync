<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Channels\ChannelRegistry;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Lazada\Models\LazadaStore;
use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $m = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada'] as $e) {
            $m->install($e);
            $m->enable($e);
        }
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\Lazada\LazadaExtension::class);
        $this->artisan('permissions:sync-catalog');
    }

    public function test_every_live_store_is_an_option_paused_ones_included_and_permissions_gate_them(): void
    {
        $a = ShopeeStore::create(['mode' => 'live', 'store_name' => 'Main store']);
        $b = ShopeeStore::create(['mode' => 'live', 'store_name' => 'Outlet', 'enabled' => false]);
        LazadaStore::create(['mode' => 'live', 'store_name' => 'Laz PH', 'region' => 'PH']);

        $group = UserGroup::create(['name' => 'Shopee viewer']);
        $group->permissions()->attach(Permission::where('key', 'view_shopee/order')->pluck('id'));
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $options = app(ChannelRegistry::class)->visibleStoreOptions($user);
        $this->assertSame(['shopee:' . $a->id, 'shopee:' . $b->id], array_column($options, 'key'),
            'both Shopee stores, paused one included; no Lazada without its permission');
        $this->assertSame(
            ['key' => 'shopee:' . $a->id, 'label' => 'Main store', 'channel' => 'Shopee', 'source' => 'shopee', 'store_id' => $a->id],
            $options[0]
        );

        $both = UserGroup::create(['name' => 'Both viewer']);
        $both->permissions()->attach(Permission::whereIn('key', ['view_shopee/order', 'view_lazada/order'])->pluck('id'));
        $keys = array_column(app(ChannelRegistry::class)->visibleStoreOptions(User::factory()->create(['user_group_id' => $both->id])), 'key');
        $this->assertContains('lazada:' . LazadaStore::query()->value('id'), $keys);
    }
}
