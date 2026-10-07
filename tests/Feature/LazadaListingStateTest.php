<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\Listings\ListingState;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\lazada\Services\Lazada\LazadaListingStates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LazadaListingStateTest extends TestCase
{
    use RefreshDatabase;

    public array $getResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        LazadaSetting::query()->create([
            'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r',
        ]);

        $test = $this;
        $fake = new class($test) extends LazadaClient {
            public function __construct(private $test) {}
            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
            }
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $answer = $this->test->getResponses[$apiPath] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($params);
                }

                return $answer
                    ?? ['ok' => false, 'status' => 500, 'body' => ['code' => '500', 'message' => 'unexpected GET ' . $apiPath]];
            }
            public function sign(string $apiPath, array $params, string $appSecret): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(LazadaClient::class, $fake);
    }

    private int $groupSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada state group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'manage_lazada/product', 'view_lazada/product',
            ])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku, string $image = 'img.jpg'): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => $image, 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now()->subDays(30), 'date_modified' => now()->subDays(30), 'date_available' => now()->subDays(30),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    private function engine(): LazadaListingStates
    {
        return $this->app->make(LazadaListingStates::class);
    }

    public function test_the_truth_table_from_raw_status_to_state(): void
    {
        $expected = [
            'active' => [ListingState::LIVE, 'Live'],
            'inactive' => [ListingState::INACTIVE, 'Inactive'],
            'pending' => [ListingState::REVIEWING, 'Under review'],
            'rejected' => [ListingState::ATTENTION, 'Rejected'],
            'sold-out' => [ListingState::ATTENTION, 'Sold out'],
            'deleted' => [ListingState::ATTENTION, 'Deleted'],
            'missing' => [ListingState::ATTENTION, 'Not found on Lazada'],
            '' => [ListingState::UNKNOWN, 'Not checked yet'],
        ];

        $ids = [];
        $n = 0;
        foreach ($expected as $raw => $_) {
            $productId = $this->seedProduct('Truth ' . $n, 'TR-' . $n);
            LazadaProduct::create([
                'product_id' => $productId, 'lazada_item_id' => '90' . $n,
                'live_status' => $raw === '' ? null : $raw,
                'live_checked_at' => $raw === '' ? null : now(),
            ]);
            $ids[$raw] = $productId;
            $n++;
        }

        $states = $this->engine()->forProducts(array_values($ids));
        foreach ($expected as $raw => [$state, $label]) {
            $s = $states[$ids[$raw]];
            $this->assertSame($state, $s->state, "raw '{$raw}'");
            $this->assertSame($label, $s->label(), "raw '{$raw}'");
        }
    }

    public function test_a_product_with_no_row_is_not_listed_with_catalogue_gaps(): void
    {
        $productId = $this->seedProduct('Rowless', 'RL-1', image: '');

        $s = $this->engine()->forProducts([$productId])[$productId];

        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertFalse($s->ready);
        $codes = array_column($s->missing, 'code');
        $this->assertContains(ListingState::GAP_CATEGORY, $codes);
        $this->assertContains(ListingState::GAP_IMAGE, $codes);
        $this->assertNotContains(ListingState::GAP_SKU, $codes, 'the product has a SKU');
    }

    public function test_a_row_without_an_item_id_answers_from_its_own_settings(): void
    {
        $productId = $this->seedProduct('Configured', 'CF-1');
        LazadaProduct::create(['product_id' => $productId, 'primary_category_id' => 4321]);

        $s = $this->engine()->forProducts([$productId])[$productId];

        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $codes = array_column($s->missing, 'code');
        $this->assertNotContains(ListingState::GAP_CATEGORY, $codes, 'the row carries a category');
        $this->assertContains(ListingState::GAP_SHEET, $codes, 'its template has never been read');
    }

    public function test_drift_true_false_and_null(): void
    {
        $pfx = (string) config('catalog.prefix');

        $inSync = $this->seedProduct('In sync', 'DR-1');
        LazadaProduct::create([
            'product_id' => $inSync, 'lazada_item_id' => '801',
            'live_status' => 'active', 'live_checked_at' => now(),
        ]);

        $behind = $this->seedProduct('Behind', 'DR-2');
        LazadaProduct::create([
            'product_id' => $behind, 'lazada_item_id' => '802',
            'live_status' => 'active', 'live_checked_at' => now(),
        ]);
        DB::table($pfx . 'product_description')->where('product_id', $behind)->update(['name' => 'Behind, renamed']);
        DB::table($pfx . 'product')->where('product_id', $behind)->update(['date_modified' => now()->addMinute()]);

        $savedUnchanged = $this->seedProduct('Saved unchanged', 'DR-3');
        LazadaProduct::create([
            'product_id' => $savedUnchanged, 'lazada_item_id' => '803',
            'live_status' => 'active', 'live_checked_at' => now(),
        ]);
        DB::table($pfx . 'product')->where('product_id', $savedUnchanged)->update(['date_modified' => now()->addMinute()]);

        $noListing = $this->seedProduct('No listing', 'DR-4');

        $states = $this->engine()->forProducts([$inSync, $behind, $savedUnchanged, $noListing]);

        $this->assertTrue($states[$inSync]->inSync);
        $this->assertNull($states[$inSync]->driftLabel());

        $this->assertFalse($states[$behind]->inSync);
        $this->assertSame('Catalog change', $states[$behind]->driftLabel());

        $this->assertTrue($states[$savedUnchanged]->inSync, 'a catalog saved with nothing different raises nothing');
        $this->assertNull($states[$noListing]->inSync ?? null, 'no listing: nothing to compare');
    }

    public function test_delete_from_lazada_voids_the_mirror(): void
    {
        $productId = $this->seedProduct('Doomed', 'DE-1');
        $listing = LazadaProduct::create([
            'product_id' => $productId, 'lazada_item_id' => '701',
            'live_status' => 'active', 'live_checked_at' => now(),
        ]);
        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200,
            'body' => ['code' => '0', 'data' => ['item_id' => 701, 'skus' => [['SkuId' => 9001, 'SellerSku' => 'DE-1']]]]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.delete_lazada', $listing->product_id))
            ->assertRedirect();

        $listing->refresh();
        $this->assertNull($listing->lazada_item_id);
        $this->assertNull($listing->live_status, 'the link is gone; a kept status would be stale forever');
        $this->assertNull($listing->live_checked_at);
    }

    public function test_unlink_voids_the_mirror(): void
    {
        $productId = $this->seedProduct('Cut loose', 'UN-1');
        $listing = LazadaProduct::create([
            'product_id' => $productId, 'lazada_item_id' => '702',
            'live_status' => 'inactive', 'live_checked_at' => now(),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.unlink', $listing->product_id))
            ->assertRedirect();

        $listing->refresh();
        $this->assertNull($listing->lazada_item_id);
        $this->assertNotNull($listing->unlinked_at);
        $this->assertNull($listing->live_status);
        $this->assertSame(ListingState::NOT_LISTED, $this->engine()->forProducts([$productId])[$productId]->state);
    }

    public function test_summary_counts_attention_and_drift(): void
    {
        $pfx = (string) config('catalog.prefix');

        $rejected = $this->seedProduct('Rejected', 'SM-1');
        LazadaProduct::create(['product_id' => $rejected, 'lazada_item_id' => '601', 'live_status' => 'rejected', 'live_checked_at' => now()]);

        $vanished = $this->seedProduct('Vanished', 'SM-2');
        LazadaProduct::create(['product_id' => $vanished, 'lazada_item_id' => '602', 'live_status' => 'missing', 'live_checked_at' => now()]);

        $behind = $this->seedProduct('Behind', 'SM-3');
        LazadaProduct::create(['product_id' => $behind, 'lazada_item_id' => '603', 'live_status' => 'active', 'live_checked_at' => now()]);
        DB::table($pfx . 'product_description')->where('product_id', $behind)->update(['name' => 'Behind, renamed']);
        DB::table($pfx . 'product')->where('product_id', $behind)->update(['date_modified' => now()->addMinute()]);

        $unlinked = $this->seedProduct('Unlinked leftover', 'SM-4');
        LazadaProduct::create(['product_id' => $unlinked, 'lazada_item_id' => '604', 'live_status' => 'rejected', 'live_checked_at' => now(), 'unlinked_at' => now()]);

        $sum = $this->engine()->summary();
        $this->assertSame(2, $sum['attention']);
        $this->assertSame(1, $sum['drift']);
    }

    public function test_the_dashboard_chip_carries_the_listing_flags(): void
    {
        $rejected = $this->seedProduct('Rejected', 'DB-1');
        LazadaProduct::create(['product_id' => $rejected, 'lazada_item_id' => '501', 'live_status' => 'rejected', 'live_checked_at' => now()]);

        $data = (new LazadaExtension(app()))->dashboardData();

        $this->assertNotNull($data['lazadaSyncStatus']);
        $flags = $data['lazadaSyncStatus']->listing_flags;
        $this->assertCount(1, $flags);
        $this->assertSame('1 listing needs attention', $flags[0]['text']);
        $this->assertSame(1, $data['lazadaSyncStatus']->listing_attention);
        $this->assertStringContainsString('state=attention', (string) $flags[0]['href']);
    }

    public function test_a_product_with_no_listing_lives_on_add_products_not_this_store(): void
    {
        $this->seedProduct('My very first product', 'FIRST-1');

        $this->actingAs($this->manager())->get(route('ext.lazada.products.index'))
            ->assertOk()->assertDontSee('My very first product');

        $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.index', ['list' => 'add']))
            ->assertOk()->assertSee('My very first product')->assertSee('Add to store');
    }

    public function test_the_group_page_offers_the_toggle_the_listing_page_offers(): void
    {
        $pid = $this->seedProduct('Togglable in group', 'TGL-1');
        $listing = LazadaProduct::create([
            'product_id' => $pid, 'lazada_item_id' => '3101',
            'live_status' => 'inactive', 'live_checked_at' => now(),
        ]);
        $grp = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Toggle group', 'lazada_category_id' => 4321]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $grp->id, 'product_id' => $pid,
            'lazada_product_id' => $listing->id, 'sync_status' => 'pushed',
        ]);

        $ug = \App\Models\Admin\UserGroup::create(['name' => 'Lazada group togglers']);
        $ug->permissions()->attach(
            \App\Models\Admin\Permission::whereIn('key', [
                'view_lazada/product_group', 'manage_lazada/product_group', 'manage_lazada/product',
            ])->pluck('id')->all()
        );
        $user = \App\Models\User::factory()->create(['user_group_id' => $ug->id]);

        $page = $this->actingAs($user)->get(route('ext.lazada.product-groups.products', $grp->id))->assertOk();
        $page->assertSee('Activate on Lazada');
        $page->assertSee('data-bulk-verb="deactivate"', false);
        $page->assertSee('data-bulk-verb="activate"', false);
        $page->assertSee('data-bulk-field="product_ids[]"', false);
    }

    public function test_the_engine_is_reachable_through_the_listing_state_contract(): void
    {
        $productId = $this->seedProduct('Contracted', 'CT-1');
        LazadaProduct::create(['product_id' => $productId, 'lazada_item_id' => '401', 'live_status' => 'active', 'live_checked_at' => now()]);

        $sources = $this->app->make(IntegrationRegistry::class)->listingStateSources();
        $lazada = collect($sources)->first(fn ($src) => $src instanceof LazadaExtension);

        $this->assertNotNull($lazada, 'LazadaExtension must register as a ListingStateSource');
        $this->assertSame(ListingState::LIVE, $lazada->listingStates([$productId])[$productId]->state);
    }

    public function test_the_last_push_refusal_and_the_error_line_are_one_answer(): void
    {
        $pid = $this->seedProduct('Refused one', 'RF-1');
        \Extensions\lazada\Models\LazadaProduct::query()->create(['product_id' => $pid]);

        $states = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class);
        $states->recordOutcome($pid, 'Primary category is required before upload.');
        $s = $states->forProducts([$pid])[$pid];
        $this->assertStringContainsString('failed: Primary category is required', implode(' ', $s->reasons));
        $this->assertStringContainsString('Primary category is required', (string) $states->errors([$pid])[$pid]);

        $states->clearErrors([$pid]);
        \Extensions\lazada\Models\LazadaProduct::query()->where('product_id', $pid)->update(['last_synced_at' => now()->subMinutes(5), 'last_sync_action' => 'bulk_upload_to_lazada', 'last_sync_ok' => true]);
        $this->assertNull($states->errors([$pid])[$pid], 'a cleared refusal draws no error line');
        $this->assertSame([], $states->forProducts([$pid])[$pid]->reasons);

        $never = $this->seedProduct('Never one', 'NV-1');
        $this->assertNull($states->errors([$never])[$never]);
    }

    private function threeCombos(int $pid, array $skus): void
    {
        foreach ($skus as $i => $sku) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $pid, 'sku' => $sku, 'quantity' => 3, 'absolute_price' => 100, 'status' => 1, 'sort_order' => $i,
            ]);
        }
    }

    public function test_a_variation_the_store_sells_but_its_item_lacks_is_an_error_until_switched_off(): void
    {
        $pid = $this->seedProduct('Three sizes', 'EXL110');
        $this->threeCombos($pid, ['EXL110-1046', 'EXL110-0942', 'EXL110-1149']);
        (new LazadaProduct())->forceFill(['product_id' => $pid, 'lazada_item_id' => '555', 'live_status' => 'active'])->save();
        $storeId = (int) LazadaSetting::defaultStore()->id;
        \App\Integrations\Listings\ListingVariations::remember('lazada', $storeId, $pid, ['EXL110-1046', 'EXL110-0942']);

        $line = (string) $this->engine()->errors([$pid])[$pid];
        $this->assertStringContainsString('Not on Lazada:', $line);
        $this->assertStringContainsString('EXL110-1149', $line);
        $this->assertStringNotContainsString('EXL110-0942', $line);

        DB::table(\App\Integrations\Listings\ListingVariations::TABLE)->insert([
            'channel' => 'lazada', 'store_id' => $storeId, 'product_id' => $pid, 'sku' => 'exl110-1149',
        ]);
        $this->assertNull($this->engine()->errors([$pid])[$pid]);
    }

    public function test_a_read_of_the_item_remembers_the_seller_skus_it_holds(): void
    {
        $pid = $this->seedProduct('Read one', 'RD');
        $this->threeCombos($pid, ['RD-1', 'RD-2', 'RD-3']);
        $listing = new LazadaProduct();
        $listing->forceFill(['product_id' => $pid, 'lazada_item_id' => '777'])->save();
        $setting = LazadaSetting::defaultStore();
        $storeId = (int) $setting->id;
        $answer = fn (array $skus) => ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 777, 'status' => 'active',
            'skus' => array_map(fn ($s, $i) => ['SellerSku' => $s, 'SkuId' => 10 + $i, 'ShopSku' => 'shop-' . $i], $skus, array_keys($skus)),
        ]]];
        $cache = app(\Extensions\lazada\Services\Lazada\LazadaItemCache::class);

        $this->getResponses['/product/item/get'] = $answer(['RD-1', 'RD-2']);
        $cache->refreshListing($listing->fresh(), $setting, app(LazadaClient::class));
        $missing = \App\Integrations\Listings\ListingVariations::missing('lazada', $storeId, [$pid]);
        $this->assertSame(['RD-3'], array_column($missing[$pid] ?? [], 'sku'));

        $this->getResponses['/product/item/get'] = $answer(['RD-1', 'RD-2', 'RD-3']);
        $cache->fetchAndCacheVariants($listing->fresh(), $setting, app(LazadaClient::class));
        $this->assertSame([], \App\Integrations\Listings\ListingVariations::missing('lazada', $storeId, [$pid]));

        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 'E0001', 'message' => 'refused']];
        $cache->fetchAndCacheVariants($listing->fresh(), $setting, app(LazadaClient::class));
        $this->assertSame([], \App\Integrations\Listings\ListingVariations::missing('lazada', $storeId, [$pid]));
    }

    public function test_a_group_is_a_preset_the_product_inherits(): void
    {
        $pid = $this->seedProduct('Inherits one', 'IN-1');
        $listing = \Extensions\lazada\Models\LazadaProduct::query()->create(['product_id' => $pid]);
        $grp = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Preset group', 'lazada_category_id' => 4321, 'brand_name_override' => 'Acme']);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $grp->id, 'product_id' => $pid,
            'lazada_product_id' => $listing->id, 'sync_status' => 'pending',
        ]);

        $inherit = app(\Extensions\lazada\Services\Lazada\LazadaInheritedSettings::class);
        $filled = $inherit->fill($listing, $inherit->forProducts([$pid])[$pid] ?? null);
        $this->assertSame(4321, (int) $filled['listing']->primary_category_id);
        $this->assertSame('Acme', $filled['listing']->brand_name_override);
        $this->assertSame(['category' => 'Preset group', 'brand' => 'Preset group'], $filled['inherited']);

        $codes = array_column(app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class)
            ->forListings(collect([$listing]))[$listing->id]['gaps'], 'code');
        $this->assertNotContains(\App\Integrations\Listings\ListingState::GAP_CATEGORY, $codes);
        $this->assertContains(\App\Integrations\Listings\ListingState::GAP_SHEET, $codes, 'the inherited category is judged like its own: its sheet is still unread');

        $this->assertNull(\Extensions\lazada\Models\LazadaProduct::query()->find($listing->id)->primary_category_id);
        $this->assertNull($listing->primary_category_id);

        $own = $this->seedProduct('Own one', 'OW-1');
        $ownListing = \Extensions\lazada\Models\LazadaProduct::query()->create(['product_id' => $own, 'primary_category_id' => 99]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $grp->id, 'product_id' => $own,
            'lazada_product_id' => $ownListing->id, 'sync_status' => 'pending',
        ]);
        $filled = $inherit->fill($ownListing, $inherit->forProducts([$own])[$own] ?? null);
        $this->assertSame(99, (int) $filled['listing']->primary_category_id);
        $this->assertSame(['brand' => 'Preset group'], $filled['inherited']);
    }
    public function test_a_row_shows_the_stores_own_title_and_the_search_finds_it(): void
    {
        $titled = $this->seedProduct('Catalog pedal name', 'TTL-1');
        $plain = $this->seedProduct('Plain catalog pedal', 'PLN-1');
        $titledRow = LazadaProduct::create(['product_id' => $titled, 'item_name' => 'Qable IC50 Pro']);
        $plainRow = LazadaProduct::create(['product_id' => $plain]);
        $other = LazadaSetting::query()->create([
            'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k2', 'app_secret' => 's2',
            'access_token' => 't2', 'refresh_token' => 'r2',
        ]);
        LazadaProduct::create(['lazada_setting_id' => $other->id, 'product_id' => $plain, 'item_name' => 'Qable elsewhere']);
        $grp = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Titled pedals', 'lazada_category_id' => 4321]);
        foreach ([[$titled, $titledRow], [$plain, $plainRow]] as [$pid, $row]) {
            \Extensions\lazada\Models\LazadaProductGroupProduct::create([
                'lazada_product_group_id' => $grp->id, 'product_id' => $pid,
                'lazada_product_id' => $row->id, 'sync_status' => 'pending',
            ]);
        }
        $ug = UserGroup::create(['name' => 'Lazada titles']);
        $ug->permissions()->attach(Permission::whereIn('key', [
            'view_lazada/product', 'manage_lazada/product', 'view_lazada/product_group', 'manage_lazada/product_group',
        ])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $ug->id]);

        foreach ([route('ext.lazada.products.index'), route('ext.lazada.product-groups.products', $grp->id)] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Qable IC50 Pro')
                ->assertSee('Plain catalog pedal')
                ->assertDontSee('Catalog pedal name')
                ->assertDontSee('Qable elsewhere');
        }

        foreach ([route('ext.lazada.products.index', ['q' => 'Qable']), route('ext.lazada.product-groups.products', ['id' => $grp->id, 'q' => 'Qable'])] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Qable IC50 Pro')
                ->assertDontSee('Plain catalog pedal');
        }
    }
}
