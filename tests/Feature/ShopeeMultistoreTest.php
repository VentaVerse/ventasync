<?php

namespace Tests\Feature;

use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-store Shopee (#194, umbrella #180): the promotion of the shape
 * Venta, OpenCart and Shopify already live in. Grows a section per
 * stage; S1 pins the ground - settings are rows and every storeless
 * read goes through one named accessor.
 */
class ShopeeMultistoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(\App\Extensions\ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    // ── S1: the ground ───────────────────────────────────────────────

    public function test_default_store_is_the_first_enabled_row(): void
    {
        $disabled = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Closed shop', 'enabled' => false]);
        $first = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $second = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->assertSame($first->id, ShopeeSetting::defaultStore()?->id,
            'defaultStore must return the first ENABLED row - a disabled store must never answer for the channel.');
    }

    public function test_no_store_at_all_answers_null_not_an_error(): void
    {
        $this->assertNull(ShopeeSetting::defaultStore());
    }

    /**
     * The whole point of S1: one spelling to re-aim in S4. A raw
     * singleton read reappearing would silently pin its caller to
     * "whatever row is first" forever.
     */
    public function test_nothing_reads_the_settings_singleton_raw(): void
    {
        $offenders = [];
        $scan = array_merge(
            glob(base_path('extensions/*/Controllers/*.php')) ?: [],
            glob(base_path('extensions/*/Commands/*.php')) ?: [],
            glob(base_path('extensions/*/Services/**/*.php')) ?: [],
            glob(base_path('extensions/*/Services/*.php')) ?: [],
            glob(base_path('extensions/*/Models/*.php')) ?: [],
            glob(base_path('extensions/*/*Extension.php')) ?: [],
            glob(base_path('app/**/*.php')) ?: [],
        );
        $this->assertNotEmpty($scan, 'the scan found no files; the guard is scanning nothing');

        foreach ($scan as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source, "unreadable: {$path}");
            if (str_contains($source, 'ShopeeSetting::query()->first()') || str_contains($source, 'ShopeeSetting::first()')) {
                $offenders[] = str_replace(base_path() . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders,
            'These files read the Shopee settings singleton raw instead of ShopeeSetting::defaultStore(): '
            . implode(', ', $offenders));
    }

    // ── S2: the data carries the store ───────────────────────────────

    public function test_a_write_that_names_no_store_lands_on_the_default(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $order = \Extensions\shopee\Models\ShopeeOrder::create(['region' => 'ph', 'order_sn' => 'S2-STAMP']);

        $this->assertSame($store->id, (int) $order->shopee_setting_id,
            'the creating hook must stamp the default store, or the NOT NULL column bites every un-threaded write path');
    }

    public function test_two_stores_may_hold_the_same_order_sn_in_one_region(): void
    {
        /* THE collision #180 names: (region, order_sn) was unique, so a
         * second shop in the same region could not even store its orders. */
        $one = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $one->id, 'region' => 'ph', 'order_sn' => 'SAME-SN']);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $two->id, 'region' => 'ph', 'order_sn' => 'SAME-SN']);

        $this->assertSame(2, \Extensions\shopee\Models\ShopeeOrder::where('order_sn', 'SAME-SN')->count());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $two->id, 'region' => 'sg', 'order_sn' => 'SAME-SN']);
    }

    public function test_for_store_scope_separates_the_shops(): void
    {
        $one = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $one->id, 'region' => 'ph', 'order_sn' => 'ONE-1']);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $two->id, 'region' => 'ph', 'order_sn' => 'TWO-1']);

        $this->assertSame(['ONE-1'], \Extensions\shopee\Models\ShopeeOrder::forStore($one)->pluck('order_sn')->all());
        $this->assertSame(['TWO-1'], \Extensions\shopee\Models\ShopeeOrder::forStore($two->id)->pluck('order_sn')->all());
    }

    public function test_a_store_with_orders_refuses_deletion(): void
    {
        /* Restricted, not cascaded: dropping a store must never silently
         * erase its order history. */
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $store->id, 'region' => 'ph', 'order_sn' => 'HELD-1']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $store->delete();
    }

    // ── S3: services and crons walk the stores ───────────────────────

    private function storeWithCreds(string $name, int $shopId): ShopeeSetting
    {
        return ShopeeSetting::create([
            'mode' => 'production', 'store_name' => $name,
            'partner_id' => 100001, 'partner_key' => encrypt('pk'),
            'shop_id' => $shopId,
            'access_token' => encrypt('at-' . $shopId), 'refresh_token' => encrypt('rt-' . $shopId),
            'expires_at' => now()->addMinutes(5), 'region' => 'ph',
        ]);
    }

    public function test_token_refresh_walks_every_store_and_a_failure_stops_nothing(): void
    {
        $one = $this->storeWithCreds('Main store', 111);
        $two = $this->storeWithCreds('Outlet', 222);

        $fake = new class extends \Extensions\shopee\Services\Shopee\ShopeeClient {
            public array $askedShopIds = [];
            public function __construct() {}
            public function signAuth(int $partnerId, string $partnerKey, string $path, int $timestamp): string { return 'sig'; }
            public function postJson(string $mode, string $path, array $query, array $body): array
            {
                $this->askedShopIds[] = (int) $body['shop_id'];
                if ((int) $body['shop_id'] === 111) {
                    return ['ok' => false, 'status' => 400, 'body' => ['message' => 'invalid refresh token']];
                }
                return ['ok' => true, 'status' => 200, 'body' => ['access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expire_in' => 14400]];
            }
        };
        $this->app->instance(\Extensions\shopee\Services\Shopee\ShopeeClient::class, $fake);

        $this->artisan('shopee:refresh-token')->assertExitCode(1);

        $this->assertSame([111, 222], $fake->askedShopIds,
            'the first store failing must not stop the second from refreshing');
        $this->assertSame('fresh-access', decrypt($two->fresh()->access_token),
            'the surviving store got its new token');
        $this->assertSame('at-111', decrypt($one->fresh()->access_token),
            'the failed store keeps its old token untouched - a failed grant writes nothing');
    }

    public function test_the_mirror_sweep_stays_inside_its_store(): void
    {
        $one = $this->storeWithCreds('Main store', 111);
        $two = $this->storeWithCreds('Outlet', 222);
        $linkA = \Extensions\shopee\Models\ShopeeProductLink::create(['shopee_setting_id' => $one->id, 'product_id' => 11, 'shopee_item_id' => 901, 'live_status' => 'NORMAL']);
        $linkB = \Extensions\shopee\Models\ShopeeProductLink::create(['shopee_setting_id' => $two->id, 'product_id' => 11, 'shopee_item_id' => 902, 'live_status' => 'NORMAL']);

        $fake = new class extends \Extensions\shopee\Services\Shopee\ShopeeClient {
            public function __construct() {}
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                // Store A's shop holds only item 901, in NORMAL.
                $items = ($extraQuery['item_status'] ?? '') === 'NORMAL' ? [['item_id' => 901]] : [];
                return ['ok' => true, 'status' => 200, 'body' => ['response' => ['item' => $items, 'has_next_page' => false]]];
            }
        };

        $live = new \Extensions\shopee\Services\Shopee\ShopeeLiveListing($fake);
        $auth = ShopeeSetting::activeAuth($one->decrypted());
        $result = $live->refreshMirror($auth, (int) $one->id);

        $this->assertNull($result['error']);
        $this->assertSame('NORMAL', $linkA->fresh()->live_status);
        $this->assertSame('NORMAL', $linkB->fresh()->live_status,
            "store A's sweep must never stamp store B's links - B's item is absent from A's shop, not MISSING");
        $this->assertSame(1, $result['links'], 'the sweep counts only its own store');
    }

    // ── S4: the surfaces carry the store ─────────────────────────────

    public function test_a_store_page_lives_under_its_store_and_an_unknown_store_is_404(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'S4 walker']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_shopee/product'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $this->actingAs($u)->get('/channels/shopee/' . $store->id . '/products')->assertOk();
        $this->actingAs($u)->get('/channels/shopee/' . ($store->id + 99) . '/products')->assertNotFound();
    }

    public function test_a_single_store_era_url_rides_the_301_onto_the_default_store(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'S4 legacy']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_shopee/product'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $this->actingAs($u)->get('/channels/shopee/products?q=find-me')
            ->assertRedirect('/channels/shopee/' . $store->id . '/products?q=find-me')
            ->assertStatus(301);
    }

    public function test_route_generation_fills_the_store_from_the_default(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $this->assertStringContainsString(
            '/channels/shopee/' . $store->id . '/products',
            route('ext.shopee.products.index'),
            'a route() call with no explicit store must fill {store} from the default, or every unedited link in views and tests shatters'
        );
    }

    // ── S4b: a second store is safe ──────────────────────────────────

    public function test_a_store_page_reads_only_its_own_stores_rows(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'S4b reader']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        $one = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $pfx = (string) config('catalog.prefix');
        $pid = \Illuminate\Support\Facades\DB::table($pfx . 'product')->insertGetId([
            'model' => 'MS-1', 'sku' => 'MS-1', 'quantity' => 5, 'price' => 100, 'status' => 1,
            'image' => '', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Multi store product', 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        \Extensions\shopee\Models\ShopeeProductLink::create(['shopee_setting_id' => $one->id, 'product_id' => $pid, 'shopee_item_id' => 111111]);
        \Extensions\shopee\Models\ShopeeProductLink::create(['shopee_setting_id' => $two->id, 'product_id' => $pid, 'shopee_item_id' => 222222]);

        $pageOne = $this->actingAs($u)->get('/channels/shopee/' . $one->id . '/products')->assertOk()->getContent();
        $this->assertStringContainsString('111111', $pageOne, "store one's page shows its own link");
        $this->assertStringNotContainsString('222222', $pageOne, "store one's page must not show the other shop's item id");

        $pageTwo = $this->actingAs($u)->get('/channels/shopee/' . $two->id . '/products')->assertOk()->getContent();
        $this->assertStringContainsString('222222', $pageTwo);
        $this->assertStringNotContainsString('111111', $pageTwo);
    }

    public function test_add_store_creates_a_disabled_row_and_lands_on_its_settings(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'S4b creator']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['manage_shopee/settings', 'view_shopee/settings'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $r = $this->actingAs($u)->post(route('ext.shopee.stores.store'), ['store_name' => 'Outlet']);

        $outlet = ShopeeSetting::query()->where('store_name', 'Outlet')->first();
        $this->assertNotNull($outlet);
        $this->assertFalse((bool) $outlet->enabled, 'a new store is setup_pending until the operator switches it on');
        $r->assertRedirect(route('ext.shopee.settings.show', ['store' => $outlet->id]));
    }

    public function test_the_oauth_callback_lands_the_grant_on_the_consenting_store(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $g = \App\Models\Admin\UserGroup::create(['name' => 'S4b oauth']);
        $g->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['manage_shopee/settings', 'view_shopee/settings'])->pluck('id')->all());
        $u = \App\Models\User::factory()->create(['user_group_id' => $g->id]);
        $one = $this->storeWithCreds('Main store', 111);
        $two = $this->storeWithCreds('Outlet', 222);

        $fake = new class extends \Extensions\shopee\Services\Shopee\ShopeeClient {
            public function __construct() {}
            public function exchangeToken(string $mode, int $partnerId, string $partnerKey, string $code, int $shopId): array
            {
                return ['ok' => true, 'status' => 200, 'body' => ['access_token' => 'granted-' . $shopId, 'refresh_token' => 'r', 'expire_in' => 14400]];
            }
        };
        $this->app->instance(\Extensions\shopee\Services\Shopee\ShopeeClient::class, $fake);

        /* The callback names shop 222 - store two's shop. The grant must
         * land on store two's row and leave store one untouched, or a
         * second store's authorization would silently overwrite the
         * first's working token. */
        $this->actingAs($u)->get(route('ext.shopee.index', ['code' => 'the-code', 'shop_id' => 222]));

        $this->assertSame('granted-222', decrypt($two->fresh()->access_token), 'the grant landed on the consenting store');
        $this->assertSame('at-111', decrypt($one->fresh()->access_token), "the other store's token is untouched");
    }

    public function test_the_existing_row_became_store_one_with_a_name(): void
    {
        /* The migration names the first row so the store list never shows
         * an anonymous entry; a fresh install with no row stays empty. */
        $s = ShopeeSetting::create(['mode' => 'live']);
        $this->assertTrue((bool) $s->fresh()->enabled, 'a row created the old way is an enabled store by default');
    }
}
