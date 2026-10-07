<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
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
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app->register(\Extensions\tiktok\TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
    }

    private function user(string $tier, string $channel = 'shopee'): User
    {
        $g = UserGroup::create(['name' => $tier . ' ' . $channel . ' ' . uniqid()]);
        $g->permissions()->attach(Permission::where('key', 'like', $tier . '_' . $channel . '/%')->pluck('id'));

        return User::factory()->create(['user_group_id' => $g->id]);
    }

    private function assertNoRowsLeft(string $column, int $id): void
    {
        $tables = collect(DB::select(
            'SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?',
            [$column]
        ))->pluck('t');
        $this->assertNotEmpty($tables);
        foreach ($tables as $t) {
            $this->assertSame(0, DB::table($t)->where($column, $id)->count(), "$t still holds rows for the deleted store");
        }
    }

    public function test_deleting_a_shopee_store_empties_its_tables_and_keeps_the_other_store_and_the_core_orders(): void
    {
        $gone = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $kept = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        foreach ([$gone, $kept] as $s) {
            app()->instance('shopee.route-store', $s);
            ShopeeOrder::create(['order_sn' => 'SN-' . $s->id, 'region' => 'PH', 'status' => 'COMPLETED', 'raw' => []]);
            ShopeeListing::create(['product_id' => 1]);
            ShopeeLogistic::create(['logistics_channel_id' => 8000 + $s->id, 'logistics_channel_name' => 'J&T', 'enabled' => true]);
            DB::table('scheduled_jobs')->insert([
                'command' => 'shopee:sync-orders', 'display_name' => 'Sync', 'integration' => 'shopee', 'store_id' => $s->id,
                'cadence_value' => 1, 'cadence_unit' => 'hour', 'cron_expression' => '0 * * * *', 'enabled' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app()->forgetInstance('shopee.route-store');
        $coreId = $this->coreOrder(['marketplace_source' => 'shopee', 'marketplace_order_id' => 'SN-' . $gone->id, 'store_id' => $gone->id, 'store_name' => 'Outlet']);

        $this->actingAs($this->user('manage'))
            ->post('/channels/shopee/' . $gone->id . '/delete', ['confirm_name' => 'Outlet'])
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Outlet'));

        $this->assertDatabaseMissing('shopee_settings', ['id' => $gone->id]);
        $this->assertNoRowsLeft('shopee_setting_id', $gone->id);
        $this->assertSame(0, DB::table('scheduled_jobs')->where('integration', 'shopee')->where('store_id', $gone->id)->count());

        $this->assertDatabaseHas('shopee_settings', ['id' => $kept->id]);
        $this->assertSame(1, DB::table('shopee_orders')->where('shopee_setting_id', $kept->id)->count());
        $this->assertSame(1, DB::table('shopee_logistics')->where('shopee_setting_id', $kept->id)->count());
        $this->assertSame(1, DB::table('scheduled_jobs')->where('store_id', $kept->id)->count());

        $this->assertSame('Outlet', DB::table(config('catalog.prefix') . 'order')->where('order_id', $coreId)->value('store_name'), 'core orders keep the name');
        $this->assertDatabaseHas('activity_logs', ['action' => 'shopee.store.deleted', 'subject_id' => $gone->id]);
    }

    public function test_deleting_a_lazada_store_leaves_no_row_behind_and_keeps_the_other_store(): void
    {
        $gone = \Extensions\lazada\Models\LazadaSetting::create(['mode' => 'live', 'store_name' => 'Laz Outlet', 'region' => 'PH']);
        $kept = \Extensions\lazada\Models\LazadaSetting::create(['mode' => 'live', 'store_name' => 'Laz Main', 'region' => 'PH']);
        foreach ([$gone, $kept] as $s) {
            app()->instance('lazada.route-store', $s);
            \Extensions\lazada\Models\LazadaOrder::create(['order_id' => 900000 + $s->id, 'region' => 'PH', 'status' => 'pending', 'raw' => []]);
            \Extensions\lazada\Models\LazadaProduct::create(['product_id' => 1]);
        }
        app()->forgetInstance('lazada.route-store');

        $this->actingAs($this->user('manage', 'lazada'))
            ->post('/channels/lazada/' . $gone->id . '/delete', ['confirm_name' => 'Laz Outlet'])
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Laz Outlet'));

        $this->assertDatabaseMissing('lazada_settings', ['id' => $gone->id]);
        $this->assertNoRowsLeft('lazada_setting_id', $gone->id);
        $this->assertDatabaseHas('lazada_settings', ['id' => $kept->id]);
        $this->assertSame(1, DB::table('lazada_orders')->where('lazada_setting_id', $kept->id)->count());
        $this->assertSame(1, DB::table('lazada_products')->where('lazada_setting_id', $kept->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'lazada.store.deleted', 'subject_id' => $gone->id]);
    }

    public function test_deleting_a_tiktok_store_leaves_no_row_behind_and_keeps_the_other_store(): void
    {
        $gone = \Extensions\tiktok\Models\TikTokSetting::create(['mode' => 'production', 'store_name' => 'TT Outlet']);
        $kept = \Extensions\tiktok\Models\TikTokSetting::create(['mode' => 'production', 'store_name' => 'TT Main']);
        foreach ([$gone, $kept] as $s) {
            app()->instance('tiktok.route-store', $s);
            \Extensions\tiktok\Models\TikTokOrder::create(['order_id' => 'TT-' . $s->id, 'region' => 'PH', 'status' => 'AWAITING_SHIPMENT', 'raw' => []]);
            \Extensions\tiktok\Models\TikTokListing::create(['product_id' => 1]);
        }
        app()->forgetInstance('tiktok.route-store');

        $this->actingAs($this->user('manage', 'tiktok'))
            ->post('/channels/tiktok/' . $gone->id . '/delete', ['confirm_name' => 'TT Outlet'])
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'TT Outlet'));

        $this->assertDatabaseMissing('tiktok_settings', ['id' => $gone->id]);
        $this->assertNoRowsLeft('tiktok_setting_id', $gone->id);
        $this->assertDatabaseHas('tiktok_settings', ['id' => $kept->id]);
        $this->assertSame(1, DB::table('tiktok_orders')->where('tiktok_setting_id', $kept->id)->count());
        $this->assertSame(1, DB::table('tiktok_listings')->where('tiktok_setting_id', $kept->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'tiktok.store.deleted', 'subject_id' => $gone->id]);
    }

    public function test_the_wrong_name_and_the_view_tier_are_refused(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->actingAs($this->user('manage'))
            ->from(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->post('/channels/shopee/' . $store->id . '/delete', ['confirm_name' => 'outlet'])
            ->assertRedirect(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->assertSessionHasErrors('confirm_name');
        $this->assertDatabaseHas('shopee_settings', ['id' => $store->id]);

        $this->actingAs($this->user('view'))
            ->from(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->post('/channels/shopee/' . $store->id . '/delete', ['confirm_name' => 'Outlet'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseHas('shopee_settings', ['id' => $store->id]);
    }

    public function test_the_settings_page_offers_the_delete_to_managers_only(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->actingAs($this->user('manage'))->get(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->assertOk()->assertSee('Delete this store')->assertSee('name="confirm_name"', false)
            ->assertSee('data-expect="Outlet"', false);
        $this->actingAs($this->user('view'))->get(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->assertOk()->assertDontSee('Delete this store');
    }
}
