<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Shopee\Models\ShopeeListing;
use Extensions\Shopee\Models\ShopeeLogistic;
use Extensions\Shopee\Models\ShopeeOrder;
use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesCoreOrders;
use Tests\TestCase;

class StoreDeleteTest extends TestCase
{
    use RefreshDatabase;
    use MakesCoreOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $m = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada', 'tiktok'] as $e) {
            $m->install($e);
            $m->enable($e);
        }
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\Lazada\LazadaExtension::class);
        $this->app->register(\Extensions\TikTok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');
    }

    private function user(string $tier, string $channel = 'shopee'): User
    {
        $g = UserGroup::create(['name' => $tier . ' ' . $channel . ' ' . uniqid()]);
        $g->permissions()->attach(Permission::where('key', 'like', $tier . '_' . $channel . '/%')->pluck('id'));

        return User::factory()->create(['user_group_id' => $g->id]);
    }

    private function assertNoRowsLeft(string $channel, int $id): void
    {
        $column = 'store_id';
        $tables = collect(DB::select(
            'SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ? AND TABLE_NAME LIKE ?',
            [$column, 'ch\\_' . $channel . '\\_%']
        ))->pluck('t');
        $this->assertNotEmpty($tables);
        foreach ($tables as $t) {
            $this->assertSame(0, DB::table($t)->where($column, $id)->count(), "$t still holds rows for the deleted store");
        }
    }

    public function test_deleting_a_shopee_store_empties_its_tables_and_keeps_the_other_store_and_the_core_orders(): void
    {
        $gone = ShopeeStore::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $kept = ShopeeStore::create(['mode' => 'live', 'store_name' => 'Main store']);
        foreach ([$gone, $kept] as $s) {
            app()->instance('shopee.route-store', $s);
            ShopeeOrder::create(['order_sn' => 'SN-' . $s->id, 'region' => 'PH', 'status' => 'COMPLETED', 'raw' => []]);
            ShopeeListing::create(['product_id' => 1]);
            ShopeeLogistic::create(['logistics_channel_id' => 8000 + $s->id, 'logistics_channel_name' => 'J&T', 'enabled' => true]);
            DB::table('scheduled_jobs')->insert([
                'command' => 'shopee:sync-orders', 'display_name' => 'Sync', 'extension' => 'shopee', 'store_id' => $s->id,
                'cadence_value' => 1, 'cadence_unit' => 'hour', 'cron_expression' => '0 * * * *', 'enabled' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app()->forgetInstance('shopee.route-store');
        $coreId = $this->coreOrder(['channel' => 'shopee', 'marketplace_order_id' => 'SN-' . $gone->id, 'store_id' => $gone->id, 'store_name' => 'Outlet']);

        $this->actingAs($this->user('manage'))
            ->post('/channels/shopee/' . $gone->id . '/delete', ['confirm_name' => 'Outlet'])
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Outlet'));

        $this->assertDatabaseMissing('ch_shopee_stores', ['id' => $gone->id]);
        $this->assertNoRowsLeft('shopee', $gone->id);
        $this->assertSame(0, DB::table('scheduled_jobs')->where('extension', 'shopee')->where('store_id', $gone->id)->count());

        $this->assertDatabaseHas('ch_shopee_stores', ['id' => $kept->id]);
        $this->assertSame(1, DB::table('ch_shopee_orders')->where('store_id', $kept->id)->count());
        $this->assertSame(1, DB::table('ch_shopee_logistics')->where('store_id', $kept->id)->count());
        $this->assertSame(1, DB::table('scheduled_jobs')->where('store_id', $kept->id)->count());

        $this->assertSame('Outlet', DB::table('orders')->where('order_id', $coreId)->value('store_name'), 'core orders keep the name');
        $this->assertDatabaseHas('activity_logs', ['action' => 'shopee.store.deleted', 'subject_id' => $gone->id]);
    }

    public function test_deleting_a_lazada_store_leaves_no_row_behind_and_keeps_the_other_store(): void
    {
        $gone = \Extensions\Lazada\Models\LazadaStore::create(['mode' => 'live', 'store_name' => 'Laz Outlet', 'region' => 'PH']);
        $kept = \Extensions\Lazada\Models\LazadaStore::create(['mode' => 'live', 'store_name' => 'Laz Main', 'region' => 'PH']);
        foreach ([$gone, $kept] as $s) {
            app()->instance('lazada.route-store', $s);
            \Extensions\Lazada\Models\LazadaOrder::create(['order_id' => 900000 + $s->id, 'region' => 'PH', 'status' => 'pending', 'raw' => []]);
            \Extensions\Lazada\Models\LazadaListing::create(['product_id' => 1]);
        }
        app()->forgetInstance('lazada.route-store');

        $this->actingAs($this->user('manage', 'lazada'))
            ->post('/channels/lazada/' . $gone->id . '/delete', ['confirm_name' => 'Laz Outlet'])
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Laz Outlet'));

        $this->assertDatabaseMissing('ch_lazada_stores', ['id' => $gone->id]);
        $this->assertNoRowsLeft('lazada', $gone->id);
        $this->assertDatabaseHas('ch_lazada_stores', ['id' => $kept->id]);
        $this->assertSame(1, DB::table('ch_lazada_orders')->where('store_id', $kept->id)->count());
        $this->assertSame(1, DB::table('ch_lazada_listings')->where('store_id', $kept->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'lazada.store.deleted', 'subject_id' => $gone->id]);
    }

    public function test_deleting_a_tiktok_store_leaves_no_row_behind_and_keeps_the_other_store(): void
    {
        $gone = \Extensions\TikTok\Models\TikTokStore::create(['mode' => 'production', 'store_name' => 'TT Outlet']);
        $kept = \Extensions\TikTok\Models\TikTokStore::create(['mode' => 'production', 'store_name' => 'TT Main']);
        foreach ([$gone, $kept] as $s) {
            app()->instance('tiktok.route-store', $s);
            \Extensions\TikTok\Models\TikTokOrder::create(['order_id' => 'TT-' . $s->id, 'region' => 'PH', 'status' => 'AWAITING_SHIPMENT', 'raw' => []]);
            \Extensions\TikTok\Models\TikTokListing::create(['product_id' => 1]);
        }
        app()->forgetInstance('tiktok.route-store');

        $this->actingAs($this->user('manage', 'tiktok'))
            ->post('/channels/tiktok/' . $gone->id . '/delete', ['confirm_name' => 'TT Outlet'])
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'TT Outlet'));

        $this->assertDatabaseMissing('ch_tiktok_stores', ['id' => $gone->id]);
        $this->assertNoRowsLeft('tiktok', $gone->id);
        $this->assertDatabaseHas('ch_tiktok_stores', ['id' => $kept->id]);
        $this->assertSame(1, DB::table('ch_tiktok_orders')->where('store_id', $kept->id)->count());
        $this->assertSame(1, DB::table('ch_tiktok_listings')->where('store_id', $kept->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'tiktok.store.deleted', 'subject_id' => $gone->id]);
    }

    public function test_the_wrong_name_and_the_view_tier_are_refused(): void
    {
        $store = ShopeeStore::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->actingAs($this->user('manage'))
            ->from(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->post('/channels/shopee/' . $store->id . '/delete', ['confirm_name' => 'outlet'])
            ->assertRedirect(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->assertSessionHasErrors('confirm_name');
        $this->assertDatabaseHas('ch_shopee_stores', ['id' => $store->id]);

        $this->actingAs($this->user('view'))
            ->from(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->post('/channels/shopee/' . $store->id . '/delete', ['confirm_name' => 'Outlet'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseHas('ch_shopee_stores', ['id' => $store->id]);
    }

    public function test_the_settings_page_offers_the_delete_to_managers_only(): void
    {
        $store = ShopeeStore::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->actingAs($this->user('manage'))->get(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->assertOk()->assertSee('Delete this store')->assertSee('name="confirm_name"', false)
            ->assertSee('data-expect="Outlet"', false);
        $this->actingAs($this->user('view'))->get(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->assertOk()->assertDontSee('Delete this store');
    }
}
