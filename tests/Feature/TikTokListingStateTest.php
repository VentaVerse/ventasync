<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\Listings\ListingState;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokListingStates;
use Extensions\tiktok\TiktokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokListingStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3),
        ]);
    }

    private int $groupSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok state group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'view_tiktok/product', 'manage_tiktok/product',
                'view_tiktok/product_group', 'manage_tiktok/product_group',
                'view_tiktok/listing', 'manage_tiktok/listing',
            ])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku, string $image = 'catalog/x.png'): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => $image, 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
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

    private function engine(): TikTokListingStates
    {
        return $this->app->make(TikTokListingStates::class);
    }

    public function test_the_truth_table_from_raw_status_to_state(): void
    {
        $expected = [
            'ACTIVATE' => [ListingState::LIVE, 'Live'],
            'SELLER_DEACTIVATED' => [ListingState::INACTIVE, 'Deactivated'],
            'IN_REVIEW' => [ListingState::REVIEWING, 'Under review'],
            'PENDING' => [ListingState::REVIEWING, 'Under review'],
            'FAILED' => [ListingState::ATTENTION, 'Failed review'],
            'PLATFORM_DEACTIVATED' => [ListingState::ATTENTION, 'Deactivated by TikTok'],
            'FREEZE' => [ListingState::ATTENTION, 'Frozen'],
            'DRAFT' => [ListingState::ATTENTION, 'Draft'],
            'DELETED' => [ListingState::ATTENTION, 'Deleted'],
            'MISSING' => [ListingState::ATTENTION, 'Not found on TikTok Shop'],
            '' => [ListingState::UNKNOWN, 'Not checked yet'],
        ];

        $ids = [];
        $n = 0;
        foreach ($expected as $raw => $_) {
            $productId = $this->seedProduct('Truth ' . $n, 'TT-' . $n);
            TikTokListing::create([
                'product_id' => $productId, 'tiktok_product_id' => 'tt-90' . $n,
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

    public function test_a_rowless_product_is_not_listed_with_coded_gaps(): void
    {
        $productId = $this->seedProduct('Rowless', 'RL-1', image: '');

        $s = $this->engine()->forProducts([$productId])[$productId];

        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertFalse($s->ready);
        $codes = array_column($s->missing, 'code');
        $this->assertContains(ListingState::GAP_CATEGORY, $codes);
        $this->assertContains(ListingState::GAP_IMAGE, $codes);
    }

    public function test_a_pivot_only_product_counts_as_linked(): void
    {
        $productId = $this->seedProduct('Pivot legacy', 'PV-1');
        $grp = TikTokProductGroup::create(['name' => 'Legacy group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $grp->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-legacy', 'sync_status' => 'pushed',
        ]);

        $s = $this->engine()->forProducts([$productId])[$productId];

        $this->assertSame(ListingState::UNKNOWN, $s->state, 'linked through the pivot, mirror never read');
        $this->assertSame('Not checked yet', $s->label());
    }

    public function test_drift_true_false_and_null(): void
    {
        $pfx = (string) config('catalog.prefix');

        $inSync = $this->seedProduct('In sync', 'DR-1');
        TikTokListing::create(['product_id' => $inSync, 'tiktok_product_id' => 'tt-801', 'live_status' => 'ACTIVATE', 'live_checked_at' => now(), 'last_pushed_at' => now()]);
        DB::table($pfx . 'product')->where('product_id', $inSync)->update(['date_modified' => now()->addMinute()]);

        $behind = $this->seedProduct('Behind', 'DR-2');
        TikTokListing::create(['product_id' => $behind, 'tiktok_product_id' => 'tt-802', 'live_status' => 'ACTIVATE', 'live_checked_at' => now(), 'last_pushed_at' => now()]);
        DB::table($pfx . 'product_description')->where('product_id', $behind)->update(['name' => 'Behind, renamed']);
        DB::table($pfx . 'product')->where('product_id', $behind)->update(['date_modified' => now()->addMinute()]);

        $notListed = $this->seedProduct('Not listed yet', 'DR-3');
        TikTokListing::create(['product_id' => $notListed]);
        DB::table($pfx . 'product_description')->where('product_id', $notListed)->update(['name' => 'Not listed, renamed']);
        DB::table($pfx . 'product')->where('product_id', $notListed)->update(['date_modified' => now()->addMinute()]);

        $pivotOnly = $this->seedProduct('Pivot only', 'DR-4');
        $grp = TikTokProductGroup::create(['name' => 'Drift group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $grp->id, 'product_id' => $pivotOnly, 'tiktok_product_id' => 'tt-804', 'sync_status' => 'pushed']);

        $states = $this->engine()->forProducts([$inSync, $behind, $notListed, $pivotOnly]);

        $this->assertTrue($states[$inSync]->inSync, 'a catalog save that changed nothing is not a change');
        $this->assertFalse($states[$behind]->inSync);
        $this->assertSame('Catalog change', $states[$behind]->driftLabel());
        $this->assertSame([], $states[$behind]->reasons, 'the button says it; no reason line repeats it');
        $this->assertFalse($states[$notListed]->inSync, 'a listing not on TikTok yet keeps its own copy too');
        $this->assertNull($states[$pivotOnly]->inSync, 'no listing row: nothing to compare');
    }

    public function test_unlink_voids_the_mirror(): void
    {
        $productId = $this->seedProduct('Cut loose', 'UN-1');
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-701', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.unlink', $productId))
            ->assertRedirect();

        $listing = TikTokListing::query()->where('product_id', $productId)->first();
        $this->assertNull($listing->tiktok_product_id);
        $this->assertNull($listing->live_status, 'a kept status would be stale forever');
        $this->assertSame(ListingState::NOT_LISTED, $this->engine()->forProducts([$productId])[$productId]->state);
    }

    public function test_group_unlink_clears_the_listing_row_too(): void
    {
        $productId = $this->seedProduct('Group cut', 'GC-1');
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-702', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);
        $grp = TikTokProductGroup::create(['name' => 'Cutting group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $grp->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-702', 'sync_status' => 'pushed',
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.product-groups.unlinkProduct', [$grp->id, $productId]))
            ->assertRedirect();

        $listing = TikTokListing::query()->where('product_id', $productId)->first();
        $this->assertNull($listing->tiktok_product_id, 'the listing row lets go too');
        $this->assertNull($listing->live_status);
        $this->assertSame(ListingState::NOT_LISTED, $this->engine()->forProducts([$productId])[$productId]->state);
    }

    public function test_summary_counts_attention_and_drift(): void
    {
        $pfx = (string) config('catalog.prefix');

        $frozen = $this->seedProduct('Frozen', 'SM-1');
        TikTokListing::create(['product_id' => $frozen, 'tiktok_product_id' => 'tt-601', 'live_status' => 'FREEZE', 'live_checked_at' => now()]);

        $vanished = $this->seedProduct('Vanished', 'SM-2');
        TikTokListing::create(['product_id' => $vanished, 'tiktok_product_id' => 'tt-602', 'live_status' => 'MISSING', 'live_checked_at' => now()]);

        $behind = $this->seedProduct('Behind', 'SM-3');
        TikTokListing::create(['product_id' => $behind, 'tiktok_product_id' => 'tt-603', 'live_status' => 'ACTIVATE', 'live_checked_at' => now(), 'last_pushed_at' => now()]);
        DB::table($pfx . 'product_description')->where('product_id', $behind)->update(['name' => 'Behind, renamed']);
        DB::table($pfx . 'product')->where('product_id', $behind)->update(['date_modified' => now()->addMinute()]);

        $touched = $this->seedProduct('Touched', 'SM-5');
        TikTokListing::create(['product_id' => $touched, 'tiktok_product_id' => 'tt-605', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);
        DB::table($pfx . 'product')->where('product_id', $touched)->update(['date_modified' => now()->addMinute()]);

        $unlinked = $this->seedProduct('Unlinked leftover', 'SM-4');
        TikTokListing::create(['product_id' => $unlinked, 'tiktok_product_id' => null, 'live_status' => 'FREEZE', 'live_checked_at' => now()]);

        $sum = $this->engine()->summary();
        $this->assertSame(2, $sum['attention']);
        $this->assertSame(1, $sum['drift']);
    }

    public function test_the_dashboard_chip_carries_the_listing_flags(): void
    {
        $failed = $this->seedProduct('Failed', 'DB-1');
        TikTokListing::create(['product_id' => $failed, 'tiktok_product_id' => 'tt-501', 'live_status' => 'FAILED', 'live_checked_at' => now()]);

        $data = (new TiktokExtension(app()))->dashboardData();

        $this->assertNotNull($data['tiktokSyncStatus']);
        $flags = $data['tiktokSyncStatus']->listing_flags;
        $this->assertCount(1, $flags);
        $this->assertSame('1 listing needs attention', $flags[0]['text']);
        $this->assertSame(1, $data['tiktokSyncStatus']->listing_attention);
        $this->assertStringContainsString('state=attention', (string) $flags[0]['href']);
    }

    public function test_a_product_on_the_store_with_no_push_is_visible_by_default(): void
    {
        $pid = $this->seedProduct('My very first product', 'FIRST-1');
        TikTokListing::create(['product_id' => $pid]);

        $page = $this->actingAs($this->manager())->get(route('ext.tiktok.products.index'))->assertOk();
        $page->assertSee('My very first product');
        $page->assertSee('Not listed');

        $this->actingAs($this->manager())
            ->get(route('ext.tiktok.products.index', ['sync_status' => 'not_listed']))
            ->assertOk()->assertSee('My very first product');
    }

    public function test_the_engine_is_reachable_through_the_listing_state_contract(): void
    {
        $productId = $this->seedProduct('Contracted', 'CT-1');
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-401', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);

        $sources = $this->app->make(IntegrationRegistry::class)->listingStateSources();
        $tiktok = collect($sources)->first(fn ($src) => $src instanceof TiktokExtension);

        $this->assertNotNull($tiktok, 'TiktokExtension must register as a ListingStateSource');
        $this->assertSame(ListingState::LIVE, $tiktok->listingStates([$productId])[$productId]->state);
        $this->assertSame(route('ext.tiktok.listings.edit', $productId), $tiktok->listingUrls([$productId])[$productId]);
    }

    public function test_the_last_push_refusal_and_the_last_sync_are_one_answer(): void
    {
        $pid = $this->seedProduct('Refused one', 'RF-1');
        \Extensions\tiktok\Models\TikTokListing::query()->create(['product_id' => $pid]);

        app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)->recordOutcome($pid, 'Main image is required.');
        $states = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class);
        $s = $states->forProducts([$pid])[$pid];
        $this->assertStringContainsString('failed: Main image is required', implode(' ', $s->reasons));
        $this->assertStringContainsString('Main image is required', (string) $states->errors([$pid])[$pid]);

        \Extensions\tiktok\Models\TikTokListing::recordPush($pid, 'tt-1', null, 'listing');
        $this->assertNull($states->errors([$pid])[$pid]);
        $this->assertNull(\Extensions\tiktok\Models\TikTokListing::query()->where('product_id', $pid)->value('last_push_error'));

        $never = $this->seedProduct('Never one', 'NV-1');
        $this->assertNull($states->errors([$never])[$never]);
    }

    public function test_a_variation_the_store_sells_but_its_item_lacks_is_an_error_until_switched_off(): void
    {
        $pid = $this->seedProduct('Three sizes', 'EXL110');
        foreach (['EXL110-1046', 'EXL110-0942', 'EXL110-1149'] as $i => $sku) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $pid, 'sku' => $sku, 'quantity' => 3, 'absolute_price' => 100, 'status' => 1, 'sort_order' => $i,
            ]);
        }
        TikTokListing::query()->create(['product_id' => $pid, 'tiktok_product_id' => 'tt-9', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);
        $storeId = (int) TikTokSetting::defaultStore()->id;
        \App\Integrations\Listings\ListingVariations::remember('tiktok', $storeId, $pid, ['EXL110-1046', 'EXL110-0942']);

        $line = (string) $this->engine()->errors([$pid])[$pid];
        $this->assertStringContainsString('Not on TikTok Shop:', $line);
        $this->assertStringContainsString('EXL110-1149', $line);
        $this->assertStringNotContainsString('EXL110-0942', $line);

        DB::table(\App\Integrations\Listings\ListingVariations::TABLE)->insert([
            'channel' => 'tiktok', 'store_id' => $storeId, 'product_id' => $pid, 'sku' => 'exl110-1149',
        ]);
        $this->assertNull($this->engine()->errors([$pid])[$pid]);
    }

    public function test_a_group_is_a_preset_the_product_inherits(): void
    {
        $pid = $this->seedProduct('Inherits one', 'IN-1');
        $listing = TikTokListing::query()->create(['product_id' => $pid]);
        $grp = TikTokProductGroup::create(['name' => 'Preset group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $grp->id, 'product_id' => $pid,
            'sync_status' => 'pending',
        ]);

        $inherit = app(\Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::class);
        $filled = $inherit->fill($listing, $inherit->forProducts([$pid])[$pid] ?? null);
        $this->assertSame('900009', $filled['listing']->tiktok_category_id);
        $this->assertSame(['category' => 'Preset group'], $filled['inherited']);

        $codes = array_column(app(\Extensions\tiktok\Services\TikTok\TikTokListingReadiness::class)
            ->forProducts([$pid])[$pid]['gaps'], 'code');
        $this->assertNotContains(\App\Integrations\Listings\ListingState::GAP_CATEGORY, $codes);
        $this->assertContains(\App\Integrations\Listings\ListingState::GAP_SHEET, $codes, 'the inherited category is judged like its own: its sheet is still unread');

        $this->assertNull(TikTokListing::query()->where('product_id', $pid)->value('tiktok_category_id'));
        $this->assertNull($listing->tiktok_category_id);

        $own = $this->seedProduct('Own one', 'OW-1');
        $ownListing = TikTokListing::query()->create(['product_id' => $own, 'tiktok_category_id' => '900777']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $grp->id, 'product_id' => $own, 'sync_status' => 'pending']);
        $filled = $inherit->fill($ownListing, $inherit->forProducts([$own])[$own] ?? null);
        $this->assertSame('900777', $filled['listing']->tiktok_category_id);
        $this->assertSame([], $filled['inherited']);
    }

    private function linkedSingle(string $name, string $sku, string $ttId): int
    {
        $pid = $this->seedProduct($name, $sku);
        TikTokListing::query()->create([
            'product_id' => $pid, 'tiktok_product_id' => $ttId,
            'tiktok_sku_id' => json_encode(['__single__' => 's-' . $ttId]),
            'live_status' => 'ACTIVATE', 'live_checked_at' => now(),
        ]);

        return $pid;
    }

    private static function tiktokAnswer(int $code = 0, string $message = 'Success', array $data = []): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['code' => $code, 'message' => $message, 'data' => $data], 200);
    }

    public function test_a_failure_then_a_success_on_another_path_clears_the_line(): void
    {
        $pid = $this->linkedSingle('Two paths', 'TP-1', 'tt-501');
        Http::fake([
            '*/prices/update*' => self::tiktokAnswer(12052700, 'Price is below the allowed floor.'),
            '*/inventory/update*' => self::tiktokAnswer(),
            '*' => self::tiktokAnswer(),
        ]);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.tiktok.products.push_price', $pid))->assertRedirect();
        $line = (string) $this->engine()->errors([$pid])[$pid];
        $this->assertStringContainsString('Price push: Price is below the allowed floor.', $line);
        $this->assertContains($pid, $this->engine()->erroredProductIds());

        $this->actingAs($user)->post(route('ext.tiktok.products.push_stock', $pid))->assertRedirect();
        $this->assertNull($this->engine()->errors([$pid])[$pid], 'a later success on another path clears the line');
        $this->assertNotContains($pid, $this->engine()->erroredProductIds());
    }

    public function test_a_stored_error_survives_a_link_check_and_a_refresh_until_a_write_succeeds(): void
    {
        $pid = $this->linkedSingle('Survivor', 'SV-1', 'tt-801');
        Http::fake([
            '*/prices/update*' => self::tiktokAnswer(12052700, 'Price is below the allowed floor.'),
            '*/products/search*' => self::tiktokAnswer(0, 'Success', ['products' => [[
                'id' => 'tt-801', 'status' => 'ACTIVATE', 'title' => 'Survivor',
                'skus' => [['id' => 's-tt-801', 'seller_sku' => 'SV-1']],
            ]]]),
            '*/inventory/update*' => self::tiktokAnswer(),
            '*' => self::tiktokAnswer(),
        ]);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.tiktok.products.push_price', $pid))->assertRedirect();
        $this->assertStringContainsString('Price is below the allowed floor.', (string) $this->engine()->errors([$pid])[$pid]);

        $this->actingAs($user)->post(route('ext.tiktok.products.check'), ['product_ids' => [$pid]])->assertRedirect();
        $this->assertStringContainsString('Price is below the allowed floor.', (string) $this->engine()->errors([$pid])[$pid]);

        $this->artisan('tiktok:refresh-listing-status')->assertExitCode(0);
        $this->assertStringContainsString('Price is below the allowed floor.', (string) $this->engine()->errors([$pid])[$pid]);

        $this->actingAs($user)->post(route('ext.tiktok.products.push_stock', $pid))->assertRedirect();
        $this->assertNull($this->engine()->errors([$pid])[$pid]);
    }

    public function test_the_link_check_sets_a_failure_when_the_item_is_gone(): void
    {
        $pid = $this->linkedSingle('Gone one', 'GN-1', 'tt-901');
        Http::fake([
            '*/products/search*' => self::tiktokAnswer(0, 'Success', ['products' => []]),
            '*' => self::tiktokAnswer(),
        ]);

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.check'), ['product_ids' => [$pid]])->assertRedirect();

        $this->assertStringContainsString('Not found on TikTok Shop', (string) $this->engine()->errors([$pid])[$pid]);
        $this->assertNull(TikTokListing::query()->where('product_id', $pid)->value('tiktok_product_id'));
    }

    public function test_unlink_clears_the_line(): void
    {
        $pid = $this->linkedSingle('Cut with an error', 'CE-1', 'tt-601');
        $grp = TikTokProductGroup::create(['name' => 'Erroring group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $grp->id, 'product_id' => $pid,
            'tiktok_product_id' => 'tt-601', 'sync_status' => 'error', 'push_error' => 'Category attribute missing.',
        ]);
        $this->engine()->recordOutcome($pid, 'Stock push: refused.');
        $this->assertNotNull($this->engine()->errors([$pid])[$pid]);

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.unlink', $pid))->assertRedirect();

        $this->assertNull($this->engine()->errors([$pid])[$pid]);
        $pivot = TikTokProductGroupProduct::query()->where('product_id', $pid)->first();
        $this->assertSame('unlinked', $pivot->sync_status, 'unlinked is kept');
        $this->assertNull($pivot->push_error);
        $this->assertNull(TikTokListing::query()->where('product_id', $pid)->value('last_push_error'));
    }

    public function test_removing_from_the_group_clears_the_line(): void
    {
        $pid = $this->linkedSingle('Leaves the group', 'LG-1', 'tt-611');
        $grp = TikTokProductGroup::create(['name' => 'Leaving group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $grp->id, 'product_id' => $pid, 'tiktok_product_id' => 'tt-611', 'sync_status' => 'pushed']);
        $this->engine()->recordOutcome($pid, 'Update refused.');

        $this->actingAs($this->manager())
            ->delete(route('ext.tiktok.product-groups.removeProduct', [$grp->id, $pid]))
            ->assertRedirect();

        $this->assertNull($this->engine()->errors([$pid])[$pid]);
    }

    public function test_the_error_filter_lists_exactly_the_rows_with_an_error_line(): void
    {
        $storeId = (int) TikTokSetting::defaultStore()->id;

        $short = $this->linkedSingle('Short a variation', 'MV1', 'tt-701');
        foreach (['MV1-A', 'MV1-B'] as $i => $sku) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $short, 'sku' => $sku, 'quantity' => 3, 'absolute_price' => 100, 'status' => 1, 'sort_order' => $i,
            ]);
        }
        \App\Integrations\Listings\ListingVariations::remember('tiktok', $storeId, $short, ['MV1-A']);

        $refused = $this->linkedSingle('Refused then fixed', 'RT-1', 'tt-702');
        $this->engine()->recordOutcome($refused, 'Stock push: refused.');

        $clean = $this->linkedSingle('Nothing wrong here', 'NW-1', 'tt-703');

        $ids = $this->engine()->erroredProductIds();
        $this->assertContains($short, $ids, 'a missing variation alone is an error line');
        $this->assertContains($refused, $ids);
        $this->assertNotContains($clean, $ids);

        $user = $this->manager();
        $page = $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => 'error']));
        $page->assertOk()->assertSee('Short a variation')->assertSee('Refused then fixed')->assertDontSee('Nothing wrong here')->assertSee('products match: Push failed', false);

        $this->engine()->recordOutcome($refused, null);
        $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => 'error']))
            ->assertOk()->assertSee('Short a variation')->assertDontSee('Refused then fixed');

        $grp = TikTokProductGroup::create(['name' => 'Filter group', 'tiktok_category_id' => '900009']);
        foreach ([$short, $refused, $clean] as $pid) {
            TikTokProductGroupProduct::create(['tiktok_product_group_id' => $grp->id, 'product_id' => $pid, 'sync_status' => 'pending']);
        }
        $this->actingAs($user)->get(route('ext.tiktok.product-groups.products', ['store' => $storeId, 'id' => $grp->id, 'sync_status' => 'error']))
            ->assertOk()->assertSee('Short a variation')->assertDontSee('Refused then fixed')->assertDontSee('Nothing wrong here')
            ->assertSee('>Has an error</option>', false);
    }

    public function test_an_outcome_lands_on_the_store_it_is_about(): void
    {
        $one = TikTokSetting::defaultStore();
        $two = TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k2', 'app_secret' => 's2',
            'access_token' => 't2', 'refresh_token' => 'r2', 'shop_cipher' => 'c2', 'expires_at' => now()->addDays(3),
        ]);
        $pid = $this->linkedSingle('On two stores', 'TS-1', 'tt-301');

        $this->engine()->forStore($two)->recordOutcome($pid, 'Stock push: refused on the second store.');

        $this->assertNull($this->engine()->forStore($one)->errors([$pid])[$pid], 'the first store is untouched');
        $this->assertStringContainsString('refused on the second store', (string) $this->engine()->forStore($two)->errors([$pid])[$pid]);
        $this->assertSame([$pid], $this->engine()->forStore($two)->erroredProductIds());

        $this->engine()->forStore($one)->clearErrors([$pid]);
        $this->assertNotNull($this->engine()->forStore($two)->errors([$pid])[$pid], 'a clear on one store leaves the other');
    }

    public function test_a_refused_toggle_sets_the_line_and_a_successful_bulk_toggle_clears_it(): void
    {
        $pid = $this->linkedSingle('Toggled one', 'TG-1', 'tt-321');
        $other = $this->linkedSingle('Toggled two', 'TG-2', 'tt-322');
        $refuse = true;
        Http::fake(function ($request) use (&$refuse) {
            if (str_contains($request->url(), '/deactivate') && $refuse) {
                return self::tiktokAnswer(12019001, 'The product is under review and cannot be deactivated.');
            }

            return self::tiktokAnswer();
        });
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.tiktok.listings.toggle', $pid), ['action' => 'deactivate'])->assertRedirect();
        $this->assertStringContainsString('Deactivate: The product is under review', (string) $this->engine()->errors([$pid])[$pid]);

        $refuse = false;
        $this->actingAs($user)->post(route('ext.tiktok.products.bulk_toggle'), ['action' => 'deactivate', 'product_ids' => [$pid, $other]])->assertRedirect();
        $this->assertNull($this->engine()->errors([$pid])[$pid], 'a successful toggle clears the line');
        $this->assertSame('SELLER_DEACTIVATED', TikTokListing::query()->where('product_id', $pid)->value('live_status'));
    }

    public function test_a_scheduled_sync_success_clears_the_listing_error_too(): void
    {
        $pid = $this->linkedSingle('Cron cleared', 'CC-1', 'tt-401');
        $this->engine()->recordOutcome($pid, 'Update refused.');

        \Extensions\tiktok\Services\TikTok\TikTokStockPricePush::recordOutcomes('stock', [$pid => ['ok' => true, 'error' => null]], TikTokSetting::defaultStore());

        $this->assertNull($this->engine()->errors([$pid])[$pid]);
        $this->assertNull(TikTokListing::query()->where('product_id', $pid)->value('last_push_error'));
    }
}
