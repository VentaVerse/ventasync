<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Lazada\Models\LazadaStore;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\TikTok\Models\TikTokStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewStoreStartsInSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada', 'tiktok'] as $id) {
            $manager->install($id);
            $manager->enable($id);
        }
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\Lazada\LazadaExtension::class);
        $this->app->register(\Extensions\TikTok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');
    }

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Stores ' . uniqid('', true)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_a_store_added_on_any_channel_starts_in_the_sandbox(): void
    {
        $user = $this->userWith(['manage_shopee/settings', 'manage_lazada/settings', 'manage_tiktok/settings']);

        foreach ([
            'shopee' => [ShopeeStore::class, 'ext.shopee.stores.store'],
            'lazada' => [LazadaStore::class, 'ext.lazada.stores.store'],
            'tiktok' => [TikTokStore::class, 'ext.tiktok.stores.store'],
        ] as $channel => [$model, $route]) {
            $this->actingAs($user)->post(route($route), ['store_name' => 'First store'])->assertRedirect();

            $store = $model::query()->latest('id')->first();
            $this->assertNotNull($store, "{$channel} created the store");
            $this->assertSame('sandbox', $store->mode, "{$channel} starts a new store in the sandbox");
            $this->assertFalse((bool) $store->enabled, "{$channel} starts a new store switched off");
        }
    }
}
