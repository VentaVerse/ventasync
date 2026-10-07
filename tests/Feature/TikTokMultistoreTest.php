<?php

namespace Tests\Feature;

use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TikTokMultistoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(\App\Extensions\ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    public function test_default_store_is_the_first_enabled_row(): void
    {
        TikTokSetting::create(['mode' => 'live', 'store_name' => 'Closed shop', 'enabled' => false]);
        $first = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        TikTokSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->assertSame($first->id, TikTokSetting::defaultStore()?->id,
            'defaultStore must return the first ENABLED row - a disabled store must never answer for the channel.');
    }

    public function test_no_store_at_all_answers_null_not_an_error(): void
    {
        $this->assertNull(TikTokSetting::defaultStore());
    }

    public function test_nothing_reads_the_settings_singleton_raw(): void
    {
        $offenders = [];
        $scan = array_merge(
            glob(base_path('extensions/tiktok/Controllers/*.php')) ?: [],
            glob(base_path('extensions/tiktok/Commands/*.php')) ?: [],
            glob(base_path('extensions/tiktok/Services/**/*.php')) ?: [],
            glob(base_path('extensions/tiktok/Services/*.php')) ?: [],
            glob(base_path('extensions/tiktok/Models/*.php')) ?: [],
            glob(base_path('extensions/tiktok/*Extension.php')) ?: [],
        );
        $this->assertNotEmpty($scan, 'the scan found no files; the guard is scanning nothing');

        foreach ($scan as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source, "unreadable: {$path}");
            if (str_contains($source, 'TikTokSetting::query()->first()') || str_contains($source, 'TikTokSetting::first()')) {
                $offenders[] = str_replace(base_path() . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders,
            'These files read the TikTok settings singleton raw instead of TikTokSetting::defaultStore(): '
            . implode(', ', $offenders));
    }

    public function test_a_write_that_names_no_store_lands_on_the_default(): void
    {
        $store = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $order = TikTokOrder::create(['region' => 'PH', 'order_id' => 'STAMP-1']);

        $this->assertSame($store->id, (int) $order->tiktok_setting_id,
            'the creating hook must stamp the default store so an un-threaded write path never lands storeless');
    }

    public function test_two_stores_may_hold_the_same_order_id(): void
    {
        $one = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        TikTokOrder::create(['tiktok_setting_id' => $one->id, 'region' => 'PH', 'order_id' => 'SAME-ID']);
        TikTokOrder::create(['tiktok_setting_id' => $two->id, 'region' => 'PH', 'order_id' => 'SAME-ID']);

        $this->assertSame(2, TikTokOrder::withoutGlobalScope('tiktokStore')->where('order_id', 'SAME-ID')->count());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        TikTokOrder::create(['tiktok_setting_id' => $two->id, 'region' => 'PH', 'order_id' => 'SAME-ID']);
    }

    public function test_the_global_scope_fences_reads_to_the_bound_store(): void
    {
        $one = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        TikTokOrder::create(['tiktok_setting_id' => $one->id, 'region' => 'PH', 'order_id' => 'ONE-1']);
        TikTokOrder::create(['tiktok_setting_id' => $two->id, 'region' => 'PH', 'order_id' => 'TWO-1']);

        app()->instance('tiktok.route-store', $one);
        $this->assertSame(['ONE-1'], TikTokOrder::pluck('order_id')->all(),
            'a bound store must see only its own rows through a plain query');
        app()->forgetInstance('tiktok.route-store');
    }

    public function test_a_store_page_lives_under_its_store_and_an_unknown_store_is_404(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'TT walker']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_tiktok/product'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        $store = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $this->actingAs($u)->get('/channels/tiktok/' . $store->id . '/products')->assertOk();
        $this->actingAs($u)->get('/channels/tiktok/' . ($store->id + 99) . '/products')->assertNotFound();
    }

    public function test_a_single_store_era_url_rides_the_301_onto_the_default_store(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'TT legacy']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_tiktok/product'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        $store = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $this->actingAs($u)->get('/channels/tiktok/products?q=find-me')
            ->assertRedirect('/channels/tiktok/' . $store->id . '/products?q=find-me')
            ->assertStatus(301);
    }

    public function test_route_generation_fills_the_store_from_the_default(): void
    {
        $store = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $this->assertStringContainsString(
            '/channels/tiktok/' . $store->id . '/products',
            route('ext.tiktok.products.index'),
            'a route() call with no explicit store must fill {store} from the default'
        );
    }

    public function test_add_store_creates_a_disabled_row_and_lands_on_its_settings(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'TT creator']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['manage_tiktok/settings', 'view_tiktok/settings'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $r = $this->actingAs($u)->post(route('ext.tiktok.stores.store'), ['store_name' => 'Outlet']);

        $outlet = TikTokSetting::query()->where('store_name', 'Outlet')->first();
        $this->assertNotNull($outlet);
        $this->assertFalse((bool) $outlet->enabled, 'a new store is setup_pending until the operator switches it on');
        $r->assertRedirect(route('ext.tiktok.index', ['store' => $outlet->id]));
    }

    public function test_the_oauth_callback_lands_the_grant_on_the_store_named_in_state(): void
    {
        $one = $this->storeWithCreds('Main store');
        $two = $this->storeWithCreds('Outlet');

        $fake = new class extends \Extensions\tiktok\Services\TikTok\TikTokClient {
            public function __construct() {}
            public function getToken(string $appKey, string $appSecret, string $authCode): array
            {
                return ['ok' => true, 'status' => 200, 'body' => ['data' => [
                    'access_token' => 'granted-token',
                    'refresh_token' => 'granted-refresh',
                    'access_token_expire_in' => now()->addDays(7)->timestamp,
                    'refresh_token_expire_in' => now()->addYear()->timestamp,
                ]]];
            }
        };
        $this->app->instance(\Extensions\tiktok\Services\TikTok\TikTokClient::class, $fake);

        $state = \Extensions\tiktok\Controllers\TikTokController::oauthStateToken($two->id, false);
        $this->get(route('ext.tiktok.callback', ['code' => 'the-code', 'state' => $state]));

        $this->assertSame('granted-token', decrypt($two->fresh()->access_token),
            'the grant lands on the store named in state');
        $this->assertSame('at-old', decrypt($one->fresh()->access_token),
            "the other store's token is untouched");
    }

    public function test_a_forged_state_is_refused_and_writes_nothing(): void
    {
        $one = $this->storeWithCreds('Main store');

        $forged = $one->id . '.0.' . str_repeat('a', 24) . '.' . str_repeat('0', 64);
        $r = $this->get(route('ext.tiktok.callback', ['code' => 'attacker-code', 'state' => $forged]));

        $this->assertSame('at-old', decrypt($one->fresh()->access_token),
            'a forged state must trigger no token exchange');
        $r->assertRedirect(route('ext.tiktok.index', ['store' => $one->id]));
    }

    public function test_a_settings_write_acts_for_the_store_in_the_url_and_returns_to_it(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'TT settings']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_tiktok/settings', 'manage_tiktok/settings'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);

        $one = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->actingAs($u)
            ->post(route('ext.tiktok.toggle_mode', ['store' => $two->id]), ['mode' => 'sandbox'])
            ->assertRedirect(route('ext.tiktok.index', ['store' => $two->id]));

        $this->assertSame('sandbox', $two->fresh()->mode, "the second store's toggle must switch the second store");
        $this->assertSame('live', $one->fresh()->mode, "the second store's toggle must not touch the first store");

        $this->actingAs($u)
            ->post(route('ext.tiktok.save', ['store' => $two->id]), ['env' => 'live', 'store_name' => 'Outlet renamed', 'app_key' => 'k2'])
            ->assertRedirect(route('ext.tiktok.index', ['store' => $two->id]));

        $this->assertSame('Outlet renamed', $two->fresh()->store_name);
        $this->assertSame('Main store', $one->fresh()->store_name, "the second store's save must not touch the first store");
    }

    public function test_each_store_reports_its_own_waiting_count_to_the_dashboard(): void
    {
        $one = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        TikTokOrder::create(['tiktok_setting_id' => $one->id, 'region' => 'PH', 'order_id' => 'W-1', 'status' => 'AWAITING_SHIPMENT']);
        TikTokOrder::create(['tiktok_setting_id' => $two->id, 'region' => 'PH', 'order_id' => 'W-2', 'status' => 'AWAITING_SHIPMENT']);
        TikTokOrder::create(['tiktok_setting_id' => $two->id, 'region' => 'PH', 'order_id' => 'W-3', 'status' => 'AWAITING_SHIPMENT']);

        $data = $this->app->make(\Extensions\tiktok\TiktokExtension::class, ['app' => $this->app])->dashboardData();

        $this->assertSame(1, $data['channelPending']['tiktok:' . $one->id] ?? null, "the first store reports its own waiting count");
        $this->assertSame(2, $data['channelPending']['tiktok:' . $two->id] ?? null, "the second store reports its own");
        $this->assertArrayHasKey('tiktok:' . $two->id, $data['channelSyncStatuses'], 'each store carries its own freshness');
    }

    private function storeWithCreds(string $name): TikTokSetting
    {
        return TikTokSetting::create([
            'mode' => 'live',
            'store_name' => $name,
            'region' => 'PH',
            'app_key' => 'ak',
            'app_secret' => encrypt('as'),
            'access_token' => encrypt('at-old'),
            'refresh_token' => encrypt('rt-old'),
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_a_product_sits_in_one_group_on_each_store(): void
    {
        $one = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $group = fn ($store, string $name) => tap((new \Extensions\tiktok\Models\TikTokProductGroup())->forceFill(['tiktok_setting_id' => $store->id, 'name' => $name, 'tiktok_category_id' => '900001']))->save();
        $x = $group($one, 'Group X');
        $y = $group($one, 'Group Y');
        $onOutlet = $group($two, 'Group Y outlet');
        $shape = fn ($store) => ['pivot' => 'tiktok_product_group_products', 'fk' => 'tiktok_product_group_id', 'groups' => 'tiktok_product_groups', 'storeFk' => 'tiktok_setting_id', 'storeId' => (int) $store->id];
        $pid = 77;

        \App\Integrations\Listings\ListingGroup::assign($shape($one), $pid, (int) $x->id);
        \App\Integrations\Listings\ListingGroup::assign($shape($two), $pid, (int) $onOutlet->id);

        $this->assertSame((int) $x->id, \App\Integrations\Listings\ListingGroup::current($shape($one), $pid));
        $this->assertSame((int) $onOutlet->id, \App\Integrations\Listings\ListingGroup::current($shape($two), $pid), 'the other store groups it too');

        $claim = \App\Integrations\OneGroupRule::claim('tiktok_product_group_products', 'tiktok_product_group_id', 'tiktok_product_groups', 'tiktok_setting_id', (int) $y->id, [$pid]);
        $this->assertSame([$pid => 'Group X'], $claim['held'], 'a second group on the same store is held');

        \App\Integrations\Listings\ListingGroup::assign($shape($one), $pid, null);
        $this->assertNull(\App\Integrations\Listings\ListingGroup::current($shape($one), $pid));
        $this->assertSame((int) $onOutlet->id, \App\Integrations\Listings\ListingGroup::current($shape($two), $pid), 'No group on one store leaves the other store alone');
    }
}
