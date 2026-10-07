<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\ventacart\Models\VentaCartListing;
use Extensions\ventacart\Models\VentaCartProductGroup;
use Extensions\ventacart\Models\VentaCartProductGroupProduct;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartListingMirror;
use Extensions\ventacart\VentaCartExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VentaCartListingsIndexTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://one.ventacart.test';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('ventacart');
        $manager->enable('ventacart');
        $this->app->register(VentaCartExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        Http::preventStrayRequests();
    }

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'VentaCart index ' . (++$this->seq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        return $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group']);
    }

    private function store(): VentaCartSetting
    {
        return VentaCartSetting::create(['store_name' => 'Gear Depot', 'base_url' => self::BASE, 'api_token' => 't', 'enabled' => true]);
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

    private function scope(VentaCartSetting $store): array
    {
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Pedals']);
        $ids = [];
        foreach ([['A-1', 'Active pedal'], ['U-1', 'Unlisted pedal'], ['N-1', 'New pedal']] as [$sku, $name]) {
            $ids[$sku] = $this->product($sku, $name);
            VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $ids[$sku], 'sync_status' => 'pending']);
        }
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $ids['A-1'], 'ventacart_product_id' => 11, 'sku' => 'A-1']);
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $ids['U-1'], 'ventacart_product_id' => 12, 'sku' => 'U-1']);
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $ids['A-1'], 'live_status' => 'active', 'live_checked_at' => now()]);
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $ids['U-1'], 'live_status' => 'inactive', 'live_checked_at' => now()]);

        return $ids;
    }

    public function test_listings_is_in_every_stores_menu_between_the_sell_and_catalogue_items(): void
    {
        $store = $this->store();

        $cards = $this->app->make(VentaCartExtension::class, ['app' => $this->app])->integrationCards();
        $labels = collect($cards[0]->stores ?? [])->first()?->menu ?? [];
        $labels = array_map(fn ($m) => [$m->label, $m->routeName, $m->group], (array) $labels);

        $this->assertContains(['Listings', 'ext.ventacart.listings.index', 'Catalog'], $labels);
    }

    public function test_the_index_shows_the_menu_from_the_mirror_and_links_every_row_to_its_page(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.listings.index', $store->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="x-segment-bar"', $html);
        $this->assertStringContainsString('On Gear Depot', $html);
        $this->assertMatchesRegularExpression('/<span>Active<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>Unlisted<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>All products<\/span>\s*<span class="x-segment__count">3<\/span>/', $html);
        $this->assertStringNotContainsString('<span class="lsm-item__label">Active</span>', $html);
        $this->assertMatchesRegularExpression('/<span class="lss__label">All<\/span>\s*<span class="lss__n">3<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span class="lss__label">Live on Gear Depot<\/span>\s*<span class="lss__n">2<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span class="lsm-item__label">All products<\/span>\s*<span class="lsm-n">3<\/span>/', $html);
        $this->assertStringContainsString('<span class="co-item__sku lsm-chanid">VentaCart 11</span>', $html);
        $this->assertStringContainsString('class="x-segment-group__refresh"', $html);
        $this->assertStringNotContainsString('class="cc-head-refresh"', $html);
        foreach ($ids as $pid) {
            $this->assertStringContainsString(route('ext.ventacart.listings.edit', [$store->id, $pid]), $html);
        }
        $this->assertStringContainsString('Refresh from Gear Depot', $html);
        $this->assertStringContainsString('Unlist on Gear Depot', $html);
        Http::assertNothingSent();
    }

    public function test_the_menu_shows_and_push_failed_combines_with_listed(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        VentaCartListing::where('product_id', $ids['A-1'])->first()->forceFill(['last_push_error' => 'Push failed: refused', 'last_push_failed_at' => now()])->save();
        Http::fake();
        $user = $this->manager();

        $this->actingAs($user)->get(route('ext.ventacart.listings.index', $store->id))->assertOk()
            ->assertSee('Product group')->assertSee('Filters')->assertSee('data-lsm', false)
            ->assertDontSee('name="sync_status"', false);

        $this->actingAs($user)->get(route('ext.ventacart.listings.index', ['store' => $store->id, 'sync_status' => 'uploaded', 'failed' => 1]))->assertOk()
            ->assertSee('Active pedal')->assertDontSee('Unlisted pedal')->assertDontSee('New pedal')
            ->assertSee('matches: Live on Gear Depot · Push failed', false);

        $this->actingAs($user)->get(route('ext.ventacart.listings.index', ['store' => $store->id, 'sync_status' => 'not_listed']))->assertOk()
            ->assertSee('New pedal')->assertDontSee('Active pedal')->assertDontSee('Unlisted pedal');

        $this->actingAs($user)->get(route('ext.ventacart.listings.index', ['store' => $store->id, 'sync_status' => 'ready']))->assertOk()
            ->assertSee('New pedal')->assertDontSee('Active pedal')->assertSee('matches: Not live on Gear Depot', false);
        Http::assertNothingSent();
    }

    public function test_a_retired_eligibility_link_lands_on_its_half(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product')->whereIn('product_id', [$ids['U-1'], $ids['N-1']])->update(['sku' => '']);
        Http::fake();
        $user = $this->manager();

        foreach (['listed_not_ready', 'listed_ready'] as $retired) {
            $this->actingAs($user)->get(route('ext.ventacart.listings.index', ['store' => $store->id, 'sync_status' => $retired]))
                ->assertOk()
                ->assertSee('Unlisted pedal')->assertSee('Active pedal')->assertDontSee('New pedal');
        }

        $html = $this->actingAs($user)->get(route('ext.ventacart.listings.index', ['store' => $store->id, 'sync_status' => 'uploaded']))
            ->assertOk()
            ->assertSee('class="x-segment-bar"', false)
            ->getContent();
        $this->assertMatchesRegularExpression('/<span>All products<\/span>\s*<span class="x-segment__count">2<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>Unlisted<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>Active<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);

        $this->actingAs($user)->get(route('ext.ventacart.listings.index', ['store' => $store->id, 'sync_status' => 'uploaded', 'ventacart_tab' => 'active']))
            ->assertOk()->assertDontSee('Unlisted pedal')->assertSee('class="x-segment-bar"', false);
        Http::assertNothingSent();
    }

    public function test_a_store_tab_narrows_to_the_mirrored_status(): void
    {
        $store = $this->store();
        $this->scope($store);
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.listings.index', ['store' => $store->id, 'ventacart_tab' => 'unlisted']))
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
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $blank, 'ventacart_product_id' => 13, 'sku' => 'B-1']);

        Http::fake([self::BASE . '/api/v1/products/B-1' => Http::response(['id' => 13, 'sku' => 'B-1', 'status' => true, 'price' => 500, 'quantity' => 2])]);

        $html = $this->actingAs($this->manager())->get(route('ext.ventacart.listings.index', $store->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('Not checked yet', $html);
        $this->assertSame('active', VentaCartListing::where('product_id', $blank)->value('live_status'));
        Http::assertSentCount(1);

        $this->actingAs($this->manager())->get(route('ext.ventacart.listings.index', $store->id))->assertOk();
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
            ->post(route('ext.ventacart.listings.refresh_status', $store->id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertStringContainsString('1 unlisted', session('status'));
        $this->assertStringContainsString('1 not on the store', session('status'));
        $this->assertSame('inactive', VentaCartListing::where('product_id', $ids['A-1'])->value('live_status'));
        $this->assertSame(510.0, VentaCartListing::where('product_id', $ids['A-1'])->first()->live_price);
        $this->assertSame('missing', VentaCartListing::where('product_id', $ids['U-1'])->value('live_status'));
    }

    public function test_a_failed_walk_writes_nothing(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        Http::fake([self::BASE . '/api/v1/products*' => Http::response(['error' => 'down'], 503)]);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.refresh_status', $store->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('active', VentaCartListing::where('product_id', $ids['A-1'])->value('live_status'));
    }

    public function test_bulk_unlist_acts_on_the_linked_rows_and_names_the_skipped(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        Http::fake([self::BASE . '/api/v1/products/*' => Http::response(['id' => 11, 'status' => false])]);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.bulk_toggle', $store->id), ['action' => 'unlist', 'product_ids' => array_values($ids)])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('Unlisted 2 items on Gear Depot. 1 skipped, not on Gear Depot.', session('status'));
        Http::assertSentCount(2);
        $this->assertSame('inactive', VentaCartListing::where('product_id', $ids['A-1'])->value('live_status'));
    }

    public function test_the_command_refreshes_every_enabled_store(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        Http::fake([
            self::BASE . '/api/v1/products/A-1' => Http::response(['message' => 'Not found'], 404),
            self::BASE . '/api/v1/products/U-1' => Http::response(['data' => ['id' => 12, 'sku' => 'U-1', 'status' => true]]),
            self::BASE . '/api/v1/products*' => Http::response(['data' => [['id' => 12, 'sku' => 'U-1', 'status' => true]], 'last_page' => 1]),
        ]);

        $this->artisan('ventacart:refresh-listing-status')->assertSuccessful();

        $this->assertSame('active', VentaCartListing::where('product_id', $ids['U-1'])->value('live_status'));
        $this->assertSame('missing', VentaCartListing::where('product_id', $ids['A-1'])->value('live_status'));
        $this->assertSame(['active' => 1, 'unlisted' => 0, 'missing' => 0], VentaCartListingMirror::for($store)->counts());
        $this->assertDatabaseMissing('ventacart_product_links', ['ventacart_setting_id' => $store->id, 'product_id' => $ids['A-1']]);
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
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Tees']);
        VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'pending']);
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 21, 'sku' => 'TEE']);
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'live_status' => 'active', 'live_checked_at' => now()]);
        \App\Integrations\Listings\ListingVariations::remember('ventacart', (int) $store->id, $pid, ['TEE-S', 'TEE-M']);
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.listings.index', $store->id))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Not on Gear Depot: TEE-L.', $html);

        DB::table('listing_variation_offs')->insert([
            'channel' => 'ventacart', 'store_id' => $store->id, 'product_id' => $pid, 'sku' => 'tee-l',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.listings.index', $store->id))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Not on Gear Depot:', $html);
        Http::assertNothingSent();
    }

    public function test_the_error_filter_lists_exactly_the_rows_with_an_error_line(): void
    {
        $store = $this->store();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Mixed']);

        $tee = $this->product('TEE', 'Missing tee');
        foreach (['TEE-S', 'TEE-L'] as $i => $sku) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $tee, 'sku' => $sku, 'quantity' => 3, 'absolute_price' => 500, 'status' => 1, 'sort_order' => $i,
            ]);
        }
        \App\Integrations\Listings\ListingVariations::remember('ventacart', (int) $store->id, $tee, ['TEE-S']);
        $errored = $this->product('E-1', 'Errored pedal');
        $clean = $this->product('C-1', 'Clean pedal');

        foreach ([[$tee, 'TEE', 31], [$errored, 'E-1', 32], [$clean, 'C-1', 33]] as [$pid, $sku, $vid]) {
            VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'pushed']);
            VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => $vid, 'sku' => $sku]);
            VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'live_status' => 'active', 'live_checked_at' => now()]);
        }
        VentaCartListing::where('product_id', $errored)->first()->forceFill(['last_push_error' => 'Stock push failed: Product not found', 'last_push_failed_at' => now()])->save();
        Http::fake([self::BASE . '/*' => Http::response(['id' => 32, 'sku' => 'E-1', 'quantity' => 3])]);

        $this->assertEqualsCanonicalizing([$tee, $errored], \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store->id)->erroredProductIds());

        $markers = [
            route('ext.ventacart.listings.index', ['store' => $store->id, 'sync_status' => 'error']) => 'lsm-item lsm-flag is-on',
            route('ext.ventacart.product-groups.products', ['store' => $store->id, 'group' => $group->id, 'sync_status' => 'error']) => '>Has an error</option>',
        ];
        $pages = fn () => array_keys($markers);
        foreach ($markers as $url => $marker) {
            $html = $this->actingAs($this->manager())->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($marker, $html, $url);
            $this->assertStringContainsString('Missing tee', $html, $url);
            $this->assertStringContainsString('Errored pedal', $html, $url);
            $this->assertStringContainsString('Stock push failed: Product not found', $html, $url);
            $this->assertStringNotContainsString('Clean pedal', $html, $url);
        }

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push_stock', [$store->id, $errored]))
            ->assertRedirect()
            ->assertSessionHas('status');

        foreach ($pages() as $url) {
            $html = $this->actingAs($this->manager())->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Missing tee', $html, $url);
            $this->assertStringNotContainsString('Errored pedal', $html, $url);
        }
    }
    public function test_a_row_shows_the_stores_own_title_and_the_search_finds_it(): void
    {
        $store = $this->store();
        $ids = $this->scope($store);
        VentaCartListing::query()->where('ventacart_setting_id', $store->id)->where('product_id', $ids['A-1'])->update(['name' => 'Qable IC50 Pro']);
        $other = VentaCartSetting::create(['store_name' => 'Other Depot', 'base_url' => 'https://two.ventacart.test', 'api_token' => 't', 'enabled' => true]);
        VentaCartListing::create(['ventacart_setting_id' => $other->id, 'product_id' => $ids['U-1'], 'name' => 'Qable elsewhere']);
        $group = VentaCartProductGroup::query()->where('ventacart_setting_id', $store->id)->where('name', 'Pedals')->firstOrFail();
        Http::fake();

        foreach ([route('ext.ventacart.listings.index', $store->id), route('ext.ventacart.product-groups.products', [$store->id, $group->id])] as $url) {
            $this->actingAs($this->manager())->get($url)->assertOk()
                ->assertSee('Qable IC50 Pro')
                ->assertSee('Unlisted pedal')
                ->assertDontSee('Active pedal')
                ->assertDontSee('Qable elsewhere');
        }

        foreach ([
            route('ext.ventacart.listings.index', ['store' => $store->id, 'q' => 'Qable']),
            route('ext.ventacart.product-groups.products', ['store' => $store->id, 'group' => $group->id, 'q' => 'Qable']),
        ] as $url) {
            $this->actingAs($this->manager())->get($url)->assertOk()
                ->assertSee('Qable IC50 Pro')
                ->assertDontSee('Unlisted pedal');
        }
    }

    public function test_a_ticked_product_moves_between_groups_from_the_listings_page(): void
    {
        Http::fake();
        $store = $this->store();
        $pid = $this->product('MV-1', 'Movable pedal');
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid]);
        $groupA = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Fuzz']);
        $groupB = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Delay']);
        $manager = $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group', 'manage_ventacart/product_group']);
        $listingOnly = $this->manager();
        $back = parse_url(route('ext.ventacart.listings.index', $store->id), PHP_URL_PATH);
        $pivot = fn () => \Illuminate\Support\Facades\DB::table('ventacart_product_group_products')->where('product_id', $pid)->pluck('ventacart_product_group_id')->map(fn ($v) => (int) $v)->all();

        $this->actingAs($listingOnly)->get(route('ext.ventacart.listings.index', $store->id))->assertOk()->assertDontSee('Move to group');
        $this->actingAs($listingOnly)->post(route('ext.ventacart.product-groups.move', $store->id), ['ids' => [$pid], 'group' => $groupA->id, '_return' => $back])->assertRedirect()->assertSessionHas('error');
        $this->assertSame([], $pivot());

        $this->actingAs($manager)->get(route('ext.ventacart.listings.index', $store->id))->assertOk()->assertSee('Move to group');

        $this->actingAs($manager)->post(route('ext.ventacart.product-groups.move', $store->id), ['ids' => [$pid], 'group' => $groupA->id, '_return' => $back])
            ->assertRedirect($back)->assertSessionHas('status', '1 product moved to ' . $groupA->name . '.');
        $this->assertSame([$groupA->id], $pivot());

        $this->actingAs($manager)->post(route('ext.ventacart.product-groups.move', $store->id), ['ids' => [$pid], 'group' => $groupB->id, '_return' => $back])
            ->assertRedirect($back);
        $this->assertSame([$groupB->id], $pivot());

        $this->actingAs($manager)->get(route('ext.ventacart.listings.index', ['store' => $store->id, 'group' => $groupB->id]))->assertOk()->assertSee('Remove from group')->assertSee('Manage group');

        $this->actingAs($manager)->post(route('ext.ventacart.product-groups.move', $store->id), ['ids' => [$pid], 'group' => 'none', '_return' => $back])
            ->assertRedirect($back)->assertSessionHas('status', '1 product ungrouped.');
        $this->assertSame([], $pivot());
    }

    public function test_a_whole_group_sends_as_a_run_from_the_listings_page(): void
    {
        Http::fake();
        $store = $this->store();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Fuzz']);
        foreach ([$this->product('RN-1', 'Run pedal one'), $this->product('RN-2', 'Run pedal two')] as $pid) {
            VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid]);
            \Extensions\ventacart\Models\VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid]);
        }
        $manager = $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group', 'manage_ventacart/product_group']);
        $listingOnly = $this->manager();
        $page = route('ext.ventacart.listings.index', ['store' => $store->id, 'group' => $group->id]);

        $this->actingAs($listingOnly)->get($page)->assertOk()->assertDontSee('Send group');
        $this->actingAs($listingOnly)->post(route('ext.ventacart.product-groups.send_run_begin', [$store->id, $group->id]))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, \App\Models\AutomationRun::query()->count());

        $this->actingAs($manager)->get($page)->assertOk()->assertSee('Send group')
            ->assertSee('Send all 2 products in Fuzz to Gear Depot on group settings, rather than their own?');

        $store->update(['enabled' => false]);
        $answer = $this->actingAs($manager)->postJson(route('ext.ventacart.product-groups.send_run_begin', [$store->id, $group->id]))->assertOk()->json();
        $this->assertSame('failed', $answer['run']['status']);
        $this->assertSame(2, $answer['run']['total']);
        $this->assertSame('That VentaCart store is turned off, so nothing was sent to it. Enable it in its settings first.', $answer['outcome']);
        Http::assertNothingSent();
    }
}
