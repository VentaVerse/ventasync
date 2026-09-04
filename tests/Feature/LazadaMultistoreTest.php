<?php

namespace Tests\Feature;

use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-store Lazada (the owner 2026-09-05): the same promotion Shopee went
 * through in #194, held to the same bar. Unlike Shopee, Lazada's data tables
 * carried no store key at all - they were unique on (region, order_id) - so
 * this pass adds lazada_setting_id to the four per-seller tables, backfills,
 * and re-aims the order unique key at (store, id). See memory
 * lazada-tables-lacked-store-key. Mirrors ShopeeMultistoreTest section for
 * section.
 */
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

    // ── The ground ───────────────────────────────────────────────────

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

    /**
     * The reason defaultStore() exists: one spelling to bind. A raw singleton
     * read reappearing would silently pin its caller to "whatever row is
     * first" and never answer for the store the page is standing in.
     */
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

    // ── The data carries the store ───────────────────────────────────

    public function test_a_write_that_names_no_store_lands_on_the_default(): void
    {
        $store = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);

        $order = LazadaOrder::create(['region' => 'ph', 'order_id' => 'STAMP-1']);

        $this->assertSame($store->id, (int) $order->lazada_setting_id,
            'the creating hook must stamp the default store so an un-threaded write path never lands storeless');
    }

    public function test_two_stores_may_hold_the_same_order_id(): void
    {
        /* THE collision the missing key allowed: (region, order_id) was
         * unique, so a second store could not even store an order the first
         * already had. The key is now (lazada_setting_id, order_id). */
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

    /**
     * The read fence: on a store page the bound store scopes every query, so a
     * storeless-looking read returns only that store's rows.
     */
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

    // ── The surfaces carry the store ─────────────────────────────────

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

    /**
     * The crux of the state-param OAuth: Lazada's callback URL is one fixed
     * URL for the whole install, so the consenting store rides the `state`
     * parameter. A callback whose state names store two must land the grant on
     * store two and leave store one's token untouched.
     */
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

        // The signed state names store two as the consenting store.
        $state = \Extensions\lazada\Controllers\LazadaController::oauthStateToken($two->id, false);
        $this->get(route('lazada.callback', ['code' => 'the-code', 'state' => $state]));

        $this->assertSame('granted-token', decrypt($two->fresh()->access_token),
            'the grant lands on the store named in state');
        $this->assertSame('at-old', decrypt($one->fresh()->access_token),
            "the other store's token is untouched - a second store's grant must not overwrite the first");
    }

    /**
     * The state gate: a callback carrying a code but an unsigned, forged state
     * is refused before anything is bound or written - an attacker must not be
     * able to name a store in the state and have a code land on it.
     */
    public function test_a_forged_state_is_refused_and_writes_nothing(): void
    {
        $one = $this->storeWithCreds('Gearshipper');

        // Right shape, no valid HMAC - exactly what an attacker can craft.
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
}
