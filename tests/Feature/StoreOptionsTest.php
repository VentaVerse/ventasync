<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\shopee\Models\ShopeeSetting;
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
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->artisan('permissions:sync-catalogue');
    }

    public function test_every_live_store_is_an_option_paused_ones_included_and_permissions_gate_them(): void
    {
        $a = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $b = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet', 'enabled' => false]);
        LazadaSetting::create(['mode' => 'live', 'store_name' => 'Laz PH', 'region' => 'PH']);

        $group = UserGroup::create(['name' => 'Shopee viewer']);
        $group->permissions()->attach(Permission::where('key', 'view_shopee/order')->pluck('id'));
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $options = app(IntegrationRegistry::class)->visibleStoreOptions($user);
        $this->assertSame(['shopee:' . $a->id, 'shopee:' . $b->id], array_column($options, 'key'),
            'both Shopee stores, paused one included; no Lazada without its permission');
        $this->assertSame(
            ['key' => 'shopee:' . $a->id, 'label' => 'Main store', 'channel' => 'Shopee', 'source' => 'shopee', 'store_id' => $a->id],
            $options[0]
        );

        $both = UserGroup::create(['name' => 'Both viewer']);
        $both->permissions()->attach(Permission::whereIn('key', ['view_shopee/order', 'view_lazada/order'])->pluck('id'));
        $keys = array_column(app(IntegrationRegistry::class)->visibleStoreOptions(User::factory()->create(['user_group_id' => $both->id])), 'key');
        $this->assertContains('lazada:' . LazadaSetting::query()->value('id'), $keys);
    }
}
