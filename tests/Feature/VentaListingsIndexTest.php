<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaListingMirror;
use Extensions\venta\VentaExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The store's listings index (#171): in the menu like the Big 3, the
 * catalogue / on-store strip from the mirror (#166), rows that link to
 * the singular page, the mirror filling its own blanks, a Refresh that
 * walks the store's product list, and Unlist / Relist for a selection.
 */
class VentaListingsIndexTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://one.venta.test';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('venta');
        $manager->enable('venta');
        $this->app->register(VentaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        Http::preventStrayRequests();
    }

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Venta index ' . (++$this->seq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        return $this->userWith(['view_venta/listing', 'manage_venta/listing', 'view_venta/product_group']);
    }

    private function store(): VentaSetting
    {
        return VentaSetting::create(['store_name' => 'Gear Depot', 'base_url' => self::BASE, 'api_token' => 't', 'enabled' => true]);
    }

    private function product(string $sku, string $name): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = (int) DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'price' => 500, 'quantity' => 3, 'status' => 1, 'image' => '',
            'weight' => 0, 'length' => 0, 'width' => 0, 'height' => 0, 'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    /** Three products in a group: one active on the store, one unlisted, one never pushed. */
    private function scope(VentaSetting $store): array
    {
        $group = VentaProductGroup::create(['venta_setting_id' => $store->id, 'name' => 'Pedals']);
        $ids = [];
        foreach ([['A-1', 'Active pedal'], ['U-1', 'Unlisted pedal'], ['N-1', 'New pedal']] as [$sku, $name]) {
            $ids[$sku] = $this->product($sku, $name);
            VentaProductGroupProduct::create(['venta_product_group_id' => $group->id, 'product_id' => $ids[$sku], 'sync_status' => 'pending']);
        }
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $ids['A-1'], 'venta_product_id' => 11, 'sku' => 'A-1']);
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $ids['U-1'], 'venta_product_id' => 12, 'sku' => 'U-1']);
        VentaListing::create(['venta_setting_id' => $store->id, 'product_id' => $ids['A-1'], 'live_status' => 'active', 'live_checked_at' => now()]);
        VentaListing::create(['venta_setting_id' => $store->id, 'product_id' => $ids['U-1'], 'live_status' => 'inactive', 'live_checked_at' => now()]);

        return $ids;
    }

    public function test_listings_is_in_every_stores_menu_between_the_sell_and_catalogue_items(): void
    {
        $store = $this->store();

        $cards = $this->app->make(VentaExtension::class, ['app' => $this->app])->integrationCards();
        $labels = collect($cards[0]->stores ?? [])->first()?->menu ?? [];
        $labels = array_map(fn ($m) => [$m->label, $m->routeName, $m->group], (array) $labels);

        $this->assertContains(['Listings', 'ext.venta.listings.index', 'Catalog'], $labels);
    }

    public function test_the_index_shows_the_strip_from_the_mirror_and_links_every_row_to_its_page(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.venta.listings.index', $store->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Your catalogue', $html);
        $this->assertStringContainsString('On Gear Depot', $html);
        $this->assertMatchesRegularExpression('/<span>Active<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>Unlisted<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>All products<\/span>\s*<span class="x-segment__count">3<\/span>/', $html);
        foreach ($ids as $pid) {
            $this->assertStringContainsString(route('ext.venta.listings.edit', [$store->id, $pid]), $html);
        }
        $this->assertStringContainsString('Refresh from Gear Depot', $html);
        $this->assertStringContainsString('Unlist on Gear Depot', $html);
        Http::assertNothingSent();
    }

    public function test_a_store_tab_narrows_to_the_mirrored_status(): void
    {
        $store = $this->store();
        $this->scope($store);
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.venta.listings.index', ['store' => $store->id, 'venta_tab' => 'unlisted']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Unlisted pedal', $html);
        $this->assertStringNotContainsString('Active pedal', $html);
        $this->assertStringNotContainsString('New pedal', $html);
    }

    public function test_the_mirror_fills_its_own_blanks_once_per_linked_row(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        $blank = $this->product('B-1', 'Blank pedal');
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $blank, 'venta_product_id' => 13, 'sku' => 'B-1']);

        Http::fake([self::BASE . '/api/v1/products/B-1' => Http::response(['id' => 13, 'sku' => 'B-1', 'status' => true, 'price' => 500, 'quantity' => 2])]);

        $html = $this->actingAs($this->manager())->get(route('ext.venta.listings.index', $store->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('Not checked yet', $html);
        $this->assertSame('active', VentaListing::where('product_id', $blank)->value('live_status'));
        Http::assertSentCount(1);

        // Asked once; the next view reads the mirror.
        $this->actingAs($this->manager())->get(route('ext.venta.listings.index', $store->id))->assertOk();
        Http::assertSentCount(1);
    }

    public function test_refresh_walks_the_stores_product_list_and_files_the_absent_as_missing(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);

        Http::fake([
            self::BASE . '/api/v1/products*' => Http::response([
                'data' => [['id' => 11, 'sku' => 'A-1', 'status' => false, 'price' => 510, 'quantity' => 9]],
                'current_page' => 1, 'last_page' => 1,
            ]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.venta.listings.refresh_status', $store->id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertStringContainsString('1 unlisted', session('status'));
        $this->assertStringContainsString('1 not on the store', session('status'));
        $this->assertSame('inactive', VentaListing::where('product_id', $ids['A-1'])->value('live_status'));
        $this->assertSame(510.0, VentaListing::where('product_id', $ids['A-1'])->first()->live_price);
        $this->assertSame('missing', VentaListing::where('product_id', $ids['U-1'])->value('live_status'));
    }

    public function test_a_failed_walk_writes_nothing(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        Http::fake([self::BASE . '/api/v1/products*' => Http::response(['error' => 'down'], 503)]);

        $this->actingAs($this->manager())
            ->post(route('ext.venta.listings.refresh_status', $store->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('active', VentaListing::where('product_id', $ids['A-1'])->value('live_status'));
    }

    public function test_bulk_unlist_acts_on_the_linked_rows_and_names_the_skipped(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        Http::fake([self::BASE . '/api/v1/products/*' => Http::response(['id' => 11, 'status' => false])]);

        $this->actingAs($this->manager())
            ->post(route('ext.venta.listings.bulk_toggle', $store->id), ['action' => 'unlist', 'product_ids' => array_values($ids)])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('Unlisted 2 items on Gear Depot. 1 skipped, not on Gear Depot.', session('status'));
        Http::assertSentCount(2);
        $this->assertSame('inactive', VentaListing::where('product_id', $ids['A-1'])->value('live_status'));
    }

    public function test_the_command_refreshes_every_enabled_store(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        /* The cron re-reads the statuses AND checks the links since
         * 2026-09-07, so the store is asked for each linked SKU too: A-1 is
         * gone from the store (404, its link is dropped) and U-1 is there. */
        Http::fake([
            self::BASE . '/api/v1/products/A-1' => Http::response(['message' => 'Not found'], 404),
            self::BASE . '/api/v1/products/U-1' => Http::response(['data' => ['id' => 12, 'sku' => 'U-1', 'status' => true]]),
            self::BASE . '/api/v1/products*' => Http::response(['data' => [['id' => 12, 'sku' => 'U-1', 'status' => true]], 'last_page' => 1]),
        ]);

        $this->artisan('venta:refresh-listing-status')->assertSuccessful();

        $this->assertSame('active', VentaListing::where('product_id', $ids['U-1'])->value('live_status'));
        $this->assertSame('missing', VentaListing::where('product_id', $ids['A-1'])->value('live_status'));
        // The check dropped A-1's link, so the mirror counts one live listing.
        $this->assertSame(['active' => 1, 'unlisted' => 0, 'missing' => 0], VentaListingMirror::for($store)->counts());
        $this->assertDatabaseMissing('venta_product_links', ['venta_setting_id' => $store->id, 'product_id' => $ids['A-1']]);
    }

    public function test_a_variation_the_store_sells_but_its_item_lacks_is_named_until_it_is_switched_off(): void
    {
        $store = $this->store();
        $pid = $this->product('TEE', 'Tee');
        foreach (['TEE-S', 'TEE-M', 'TEE-L'] as $i => $sku) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $pid, 'sku' => $sku, 'quantity' => 3, 'absolute_price' => 500, 'status' => 1, 'sort_order' => $i,
            ]);
        }
        $group = VentaProductGroup::create(['venta_setting_id' => $store->id, 'name' => 'Tees']);
        VentaProductGroupProduct::create(['venta_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'pending']);
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'venta_product_id' => 21, 'sku' => 'TEE']);
        VentaListing::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'live_status' => 'active', 'live_checked_at' => now()]);
        \App\Integrations\Listings\ListingVariations::remember('venta', (int) $store->id, $pid, ['TEE-S', 'TEE-M']);
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.venta.listings.index', $store->id))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Not on Gear Depot: TEE-L.', $html);

        DB::table('listing_variation_offs')->insert([
            'channel' => 'venta', 'store_id' => $store->id, 'product_id' => $pid, 'sku' => 'tee-l',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.venta.listings.index', $store->id))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Not on Gear Depot:', $html);
        Http::assertNothingSent();
    }
}
