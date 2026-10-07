<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesCoreOrders;
use Tests\TestCase;

class DashboardStoreScopeTest extends TestCase
{
    use RefreshDatabase;
    use MakesCoreOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $m = $this->app->make(ExtensionManager::class);
        $m->install('shopee');
        $m->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
    }

    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'Shopee viewer ' . uniqid()]);
        $group->permissions()->attach(Permission::where('key', 'like', 'view_shopee/%')->pluck('id'));

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_a_store_key_scopes_the_page_and_the_ranking_names_stores(): void
    {
        $a = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $b = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $this->coreOrder(['marketplace_source' => 'shopee', 'store_id' => $a->id, 'store_name' => 'Main store', 'total' => 100]);
        $this->coreOrder(['marketplace_source' => 'shopee', 'store_id' => $b->id, 'store_name' => 'Outlet', 'total' => 300]);
        $this->coreOrder(['marketplace_source' => 'shopee', 'store_id' => 424242, 'store_name' => 'Gone store', 'total' => 50]);
        $user = $this->viewer();

        $all = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $all->assertSee('Orders by store');
        $all->assertSee('Gone store', 'a deleted store still ranks under the name it sold under');
        $all->assertSee('<optgroup label="Shopee">', false);
        $all->assertSee('name="store"', false);
        $this->assertSame(3, $all->viewData('totalOrders'));
        $this->assertSame(450.0, (float) $all->viewData('totalRevenue'));

        $one = $this->actingAs($user)->get(route('dashboard', ['store' => 'shopee:' . $b->id]))->assertOk();
        $this->assertSame(1, $one->viewData('totalOrders'));
        $this->assertSame(300.0, (float) $one->viewData('totalRevenue'));
        $this->assertSame('shopee:' . $b->id, $one->viewData('storeKey'));
        $this->assertCount(1, $one->viewData('platformSlices'));
        $this->assertCount(1, $one->viewData('recentOrders'));
        $one->assertSee(e(route('dashboard', ['range' => '30d', 'store' => 'shopee:' . $b->id])), false, 'the range links keep the store');

        $bad = $this->actingAs($user)->get(route('dashboard', ['store' => 'shopee:' . 424242]))->assertOk();
        $this->assertSame(3, $bad->viewData('totalOrders'), 'a store that is not offered means all stores');
        $this->assertSame('', $bad->viewData('storeKey'));
    }

    public function test_one_store_on_a_channel_ranks_under_the_channel_name(): void
    {
        $a = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $this->coreOrder(['marketplace_source' => 'shopee', 'store_id' => $a->id, 'store_name' => 'Main store']);

        $slices = $this->actingAs($this->viewer())->get(route('dashboard'))->assertOk()->viewData('platformSlices');
        $this->assertSame('Shopee: Main store', $slices[0]['name']);
        $this->assertSame('Main store', $slices[0]['store']);
    }
}
