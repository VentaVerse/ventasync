<?php

namespace Tests\Feature;

use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LazadaMultistoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(\App\Extensions\ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    public function test_default_store_is_the_first_enabled_row(): void
    {
        LazadaSetting::create(['mode' => 'live', 'store_name' => 'Closed shop', 'enabled' => false]);
        $first = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);
        LazadaSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->assertSame($first->id, LazadaSetting::defaultStore()?->id,
            'defaultStore must return the first ENABLED row - a disabled store must never answer for the channel.');
    }

    public function test_no_store_at_all_answers_null_not_an_error(): void
    {
        $this->assertNull(LazadaSetting::defaultStore());
    }

    public function test_nothing_reads_the_settings_singleton_raw(): void
    {
        $offenders = [];
        $scan = array_merge(
            glob(base_path('extensions/lazada/Controllers/*.php')) ?: [],
            glob(base_path('extensions/lazada/Commands/*.php')) ?: [],
            glob(base_path('extensions/lazada/Services/**/*.php')) ?: [],
            glob(base_path('extensions/lazada/Services/*.php')) ?: [],
            glob(base_path('extensions/lazada/Models/*.php')) ?: [],
            glob(base_path('extensions/lazada/*Extension.php')) ?: [],
        );
        $this->assertNotEmpty($scan, 'the scan found no files; the guard is scanning nothing');

        foreach ($scan as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source, "unreadable: {$path}");
            if (str_contains($source, 'LazadaSetting::query()->first()') || str_contains($source, 'LazadaSetting::first()')) {
                $offenders[] = str_replace(base_path() . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders,
            'These files read the Lazada settings singleton raw instead of LazadaSetting::defaultStore(): '
            . implode(', ', $offenders));
    }

    public function test_a_write_that_names_no_store_lands_on_the_default(): void
    {
        $store = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);

        $order = LazadaOrder::create(['region' => 'ph', 'order_id' => 'STAMP-1']);

        $this->assertSame($store->id, (int) $order->lazada_setting_id,
            'the creating hook must stamp the default store so an un-threaded write path never lands storeless');
    }

    public function test_two_stores_may_hold_the_same_order_id(): void
    {
        $one = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);
        $two = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        LazadaOrder::create(['lazada_setting_id' => $one->id, 'region' => 'ph', 'order_id' => 'SAME-ID']);
        LazadaOrder::create(['lazada_setting_id' => $two->id, 'region' => 'ph', 'order_id' => 'SAME-ID']);

        $this->assertSame(2, LazadaOrder::withoutGlobalScope('lazadaStore')->where('order_id', 'SAME-ID')->count());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        LazadaOrder::create(['lazada_setting_id' => $two->id, 'region' => 'ph', 'order_id' => 'SAME-ID']);
    }

    public function test_for_store_scope_separates_the_shops(): void
    {
        $one = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);
        $two = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        LazadaOrder::create(['lazada_setting_id' => $one->id, 'region' => 'ph', 'order_id' => 'ONE-1']);
        LazadaOrder::create(['lazada_setting_id' => $two->id, 'region' => 'ph', 'order_id' => 'TWO-1']);

        $this->assertSame(['ONE-1'], LazadaOrder::withoutGlobalScope('lazadaStore')->forStore($one)->pluck('order_id')->all());
        $this->assertSame(['TWO-1'], LazadaOrder::withoutGlobalScope('lazadaStore')->forStore($two->id)->pluck('order_id')->all());
    }

    public function test_the_global_scope_fences_reads_to_the_bound_store(): void
    {
        $one = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);
        $two = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        LazadaOrder::create(['lazada_setting_id' => $one->id, 'region' => 'ph', 'order_id' => 'ONE-1']);
        LazadaOrder::create(['lazada_setting_id' => $two->id, 'region' => 'ph', 'order_id' => 'TWO-1']);

        app()->instance('lazada.route-store', $one);
        $this->assertSame(['ONE-1'], LazadaOrder::pluck('order_id')->all(),
            'a bound store must see only its own rows through a plain query');
        app()->forgetInstance('lazada.route-store');
    }

    public function test_a_store_page_lives_under_its_store_and_an_unknown_store_is_404(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'LZ walker']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_lazada/product'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        $store = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);

        $this->actingAs($u)->get('/channels/lazada/' . $store->id . '/products')->assertOk();
        $this->actingAs($u)->get('/channels/lazada/' . ($store->id + 99) . '/products')->assertNotFound();
    }

    public function test_a_single_store_era_url_rides_the_301_onto_the_default_store(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'LZ legacy']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_lazada/product'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        $store = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);

        $this->actingAs($u)->get('/channels/lazada/products?q=find-me')
            ->assertRedirect('/channels/lazada/' . $store->id . '/products?q=find-me')
            ->assertStatus(301);
    }

    public function test_route_generation_fills_the_store_from_the_default(): void
    {
        $store = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);

        $this->assertStringContainsString(
            '/channels/lazada/' . $store->id . '/products',
            route('ext.lazada.products.index'),
            'a route() call with no explicit store must fill {store} from the default, or every unedited link shatters'
        );
    }

    public function test_add_store_creates_a_disabled_row_and_lands_on_its_settings(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'LZ creator']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['manage_lazada/settings', 'view_lazada/settings'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);

        $r = $this->actingAs($u)->post(route('ext.lazada.stores.store'), ['store_name' => 'Outlet']);

        $outlet = LazadaSetting::query()->where('store_name', 'Outlet')->first();
        $this->assertNotNull($outlet);
        $this->assertFalse((bool) $outlet->enabled, 'a new store is setup_pending until the operator switches it on');
        $r->assertRedirect(route('ext.lazada.index', ['store' => $outlet->id]));
    }

    public function test_the_oauth_callback_lands_the_grant_on_the_store_named_in_state(): void
    {
        $one = $this->storeWithCreds('Gearshipper');
        $two = $this->storeWithCreds('Outlet');

        $fake = new class extends \Extensions\lazada\Services\Lazada\LazadaClient {
            public function __construct() {}
            public function sign(string $apiPath, array $params, string $appSecret): string { return 'sig'; }
            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                return ['ok' => true, 'status' => 200, 'body' => [
                    'access_token' => 'granted-token',
                    'refresh_token' => 'granted-refresh',
                    'expires_in' => 172800,
                    'refresh_expires_in' => 12960000,
                ]];
            }
        };
        $this->app->instance(\Extensions\lazada\Services\Lazada\LazadaClient::class, $fake);

        $state = \Extensions\lazada\Controllers\LazadaController::oauthStateToken($two->id, false);
        $this->get(route('lazada.callback', ['code' => 'the-code', 'state' => $state]));

        $this->assertSame('granted-token', decrypt($two->fresh()->access_token),
            'the grant lands on the store named in state');
        $this->assertSame('at-old', decrypt($one->fresh()->access_token),
            "the other store's token is untouched - a second store's grant must not overwrite the first");
    }

    public function test_a_forged_state_is_refused_and_writes_nothing(): void
    {
        $one = $this->storeWithCreds('Gearshipper');

        $forged = $one->id . '.0.' . str_repeat('a', 24) . '.' . str_repeat('0', 64);
        $this->get(route('lazada.callback', ['code' => 'attacker-code', 'state' => $forged]))->assertOk();

        $this->assertSame('at-old', decrypt($one->fresh()->access_token),
            'a forged state must trigger no token exchange');
        $this->assertNull($one->fresh()->auth_code,
            'a forged state must write no auth code to any store');
    }

    private function storeWithCreds(string $name): LazadaSetting
    {
        return LazadaSetting::create([
            'mode' => 'live',
            'store_name' => $name,
            'region' => 'ph',
            'app_key' => 'ak',
            'app_secret' => 'as',
            'access_token' => encrypt('at-old'),
            'refresh_token' => encrypt('rt-old'),
            'expires_at' => now()->addDay(),
        ]);
    }
    public function test_a_settings_write_acts_for_the_store_in_the_url_and_returns_to_it(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'lazada settings walker']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_lazada/settings', 'manage_lazada/settings'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);

        $one = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->actingAs($u)
            ->post(route('ext.lazada.toggle_mode', ['store' => $two->id]), ['mode' => 'sandbox'])
            ->assertRedirect(route('ext.lazada.index', ['store' => $two->id]));

        $this->assertSame('sandbox', $two->fresh()->mode, "the second store's toggle must switch the second store");
        $this->assertSame('live', $one->fresh()->mode, "the second store's toggle must not touch the first store");

        $this->actingAs($u)
            ->post(route('ext.lazada.save', ['store' => $two->id]), ['env' => 'live', 'region' => 'ph', 'store_name' => 'Outlet renamed'])
            ->assertRedirect(route('ext.lazada.index', ['store' => $two->id]));

        $this->assertSame('Outlet renamed', $two->fresh()->store_name);
        $this->assertSame('Main store', $one->fresh()->store_name, "the second store's save must not touch the first store");
    }

    public function test_a_product_sits_in_one_group_on_each_store(): void
    {
        $one = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $group = fn ($store, string $name) => tap((new \Extensions\lazada\Models\LazadaProductGroup())->forceFill(['lazada_setting_id' => $store->id, 'name' => $name]))->save();
        $x = $group($one, 'Group X');
        $y = $group($one, 'Group Y');
        $onOutlet = $group($two, 'Group Y outlet');
        $pid = 77;
        $row = fn ($store) => tap((new \Extensions\lazada\Models\LazadaProduct())->forceFill(['lazada_setting_id' => $store->id, 'product_id' => $pid]))->save();
        $rowOne = $row($one);
        $rowTwo = $row($two);
        $shape = fn ($store) => ['pivot' => 'lazada_product_group_products', 'fk' => 'lazada_product_group_id', 'groups' => 'lazada_product_groups', 'storeFk' => 'lazada_setting_id', 'storeId' => (int) $store->id];
        $extra = fn ($listing) => ['lazada_product_id' => $listing->id, 'created_at' => now()];

        \App\Integrations\Listings\ListingGroup::assign($shape($one), $pid, (int) $x->id, $extra($rowOne));
        \App\Integrations\Listings\ListingGroup::assign($shape($two), $pid, (int) $onOutlet->id, $extra($rowTwo));

        $this->assertSame((int) $x->id, \App\Integrations\Listings\ListingGroup::current($shape($one), $pid));
        $this->assertSame((int) $onOutlet->id, \App\Integrations\Listings\ListingGroup::current($shape($two), $pid), 'the other store groups it too');

        $claim = \App\Integrations\OneGroupRule::claim('lazada_product_group_products', 'lazada_product_group_id', 'lazada_product_groups', 'lazada_setting_id', (int) $y->id, [$pid]);
        $this->assertSame([$pid => 'Group X'], $claim['held'], 'a second group on the same store is held');

        \App\Integrations\Listings\ListingGroup::assign($shape($one), $pid, null);
        $this->assertNull(\App\Integrations\Listings\ListingGroup::current($shape($one), $pid));
        $this->assertSame((int) $onOutlet->id, \App\Integrations\Listings\ListingGroup::current($shape($two), $pid), 'No group on one store leaves the other store alone');
    }

}
