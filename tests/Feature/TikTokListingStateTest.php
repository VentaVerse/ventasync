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
use Tests\TestCase;

/**
 * Phase D of the channels rebuild (#184): TikTok answers "where does this
 * product stand?" through the same ListingState vocabulary Shopee and
 * Lazada speak - one engine, rendered everywhere, never guessed. And the
 * paths that change the marketplace link stop leaving a stale mirror or
 * an orphaned listing row behind.
 */
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

    /** TikTok's raw words map onto the one vocabulary, with TikTok's own attention labels. */
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
            '' => [ListingState::UNKNOWN, 'Not checked yet'], // never checked: live_status null
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

    /** A product with no row at all is not_listed, with coded gaps. */
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

    /** A product on TikTok only through a group pivot is LINKED, honestly unchecked. */
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

    /** Drift is a trichotomy: in sync, behind the catalogue, or honestly unknowable. */
    public function test_drift_true_false_and_null(): void
    {
        $pfx = (string) config('catalog.prefix');

        $inSync = $this->seedProduct('In sync', 'DR-1');
        TikTokListing::create(['product_id' => $inSync, 'tiktok_product_id' => 'tt-801', 'live_status' => 'ACTIVATE', 'live_checked_at' => now(), 'last_pushed_at' => now()]);

        $behind = $this->seedProduct('Behind', 'DR-2');
        TikTokListing::create(['product_id' => $behind, 'tiktok_product_id' => 'tt-802', 'live_status' => 'ACTIVATE', 'live_checked_at' => now(), 'last_pushed_at' => now()->subDays(5)]);
        DB::table($pfx . 'product')->where('product_id', $behind)->update(['date_modified' => now()]);

        $neverPushed = $this->seedProduct('Matched in', 'DR-3');
        TikTokListing::create(['product_id' => $neverPushed, 'tiktok_product_id' => 'tt-803', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);

        $states = $this->engine()->forProducts([$inSync, $behind, $neverPushed]);

        $this->assertTrue($states[$inSync]->inSync);
        $this->assertFalse($states[$behind]->inSync);
        $this->assertSame('Catalogue changed', $states[$behind]->driftLabel());
        $this->assertNull($states[$neverPushed]->inSync, 'never pushed from here: nothing to compare');
    }

    /** Unlinking cuts the link AND the mirror that described it. */
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

    /**
     * A group unlink used to leave the LISTING row's link untouched, so the
     * product still read "Listed" and offered Update against a cut link.
     */
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

    /** The dashboard summary counts by aggregate. */
    public function test_summary_counts_attention_and_drift(): void
    {
        $pfx = (string) config('catalog.prefix');

        $frozen = $this->seedProduct('Frozen', 'SM-1');
        TikTokListing::create(['product_id' => $frozen, 'tiktok_product_id' => 'tt-601', 'live_status' => 'FREEZE', 'live_checked_at' => now()]);

        $vanished = $this->seedProduct('Vanished', 'SM-2');
        TikTokListing::create(['product_id' => $vanished, 'tiktok_product_id' => 'tt-602', 'live_status' => 'MISSING', 'live_checked_at' => now()]);

        $behind = $this->seedProduct('Behind', 'SM-3');
        TikTokListing::create(['product_id' => $behind, 'tiktok_product_id' => 'tt-603', 'live_status' => 'ACTIVATE', 'live_checked_at' => now(), 'last_pushed_at' => now()->subDays(5)]);
        DB::table($pfx . 'product')->where('product_id', $behind)->update(['date_modified' => now()]);

        // An unlinked leftover must not count: the trouble left with the link.
        $unlinked = $this->seedProduct('Unlinked leftover', 'SM-4');
        TikTokListing::create(['product_id' => $unlinked, 'tiktok_product_id' => null, 'live_status' => 'FREEZE', 'live_checked_at' => now()]);

        $sum = $this->engine()->summary();
        $this->assertSame(2, $sum['attention']);
        $this->assertSame(1, $sum['drift']);
    }

    /** The listing troubles ride the dashboard chip, same phrasing as the other channels. */
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
        // The figure leads to the rows it counted (#240).
        $this->assertStringContainsString('state=attention', (string) $flags[0]['href']);
    }

    /**
     * A groupless, never-pushed product the store carries is VISIBLE, and
     * not_listed finds it. Phase D (#184) made the default view the whole
     * catalogue so a new user's first product could never be invisible;
     * #201 (2026-09-05) made the view THIS STORE's own list - so the product
     * is on the store first, with the blank record Add products leaves, and
     * a group is still not required for it to show.
     */
    public function test_a_product_on_the_store_with_no_push_is_visible_by_default(): void
    {
        $pid = $this->seedProduct('My very first product', 'FIRST-1');
        TikTokListing::create(['product_id' => $pid]);

        $page = $this->actingAs($this->manager())->get(route('ext.tiktok.products.index'))->assertOk();
        $page->assertSee('My very first product');
        $page->assertSee('Not listed');

        // Before Phase D a product without a group or a link was invisible
        // even to the not_listed filter - the scope whereIn intersected it away.
        $this->actingAs($this->manager())
            ->get(route('ext.tiktok.products.index', ['sync_status' => 'not_listed']))
            ->assertOk()->assertSee('My very first product');
    }

    /** Core reaches TikTok's states through the same contract as Shopee and Lazada. */
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

    /**
     * One latest push error per product (the owner, 2026-09-05): a refusal from
     * any push lands on the listing row and every surface tells it, until
     * a push succeeds; the Last sync column is the same answer everywhere.
     */
    public function test_the_last_push_refusal_and_the_last_sync_are_one_answer(): void
    {
        $pid = $this->seedProduct('Refused one', 'RF-1');
        \Extensions\tiktok\Models\TikTokListing::query()->create(['product_id' => $pid]);

        \Extensions\tiktok\Models\TikTokListing::recordPushFailure($pid, 'Main image is required.');
        $states = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class);
        $s = $states->forProducts([$pid])[$pid];
        $this->assertStringContainsString('failed: Main image is required', implode(' ', $s->reasons));
        $this->assertStringContainsString('Main image is required', (string) $states->errors([$pid])[$pid]);

        // A successful push clears it and the error line goes.
        \Extensions\tiktok\Models\TikTokListing::recordPush($pid, 'tt-1', null, 'listing');
        $this->assertNull($states->errors([$pid])[$pid]);
        $this->assertNull(\Extensions\tiktok\Models\TikTokListing::query()->where('product_id', $pid)->value('last_push_error'));

        $never = $this->seedProduct('Never one', 'NV-1');
        $this->assertNull($states->errors([$never])[$never]);
    }

    public function test_a_group_is_a_preset_the_product_inherits(): void
    {
        // A group is a preset the product inherits (the owner, 2026-09-05): a
        // listing with no category of its own is judged, and pushed, with
        // its group's category. Nothing is written to the row.
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

        // The row keeps its blanks: the group is a preset, not a write.
        $this->assertNull(TikTokListing::query()->where('product_id', $pid)->value('tiktok_category_id'));
        $this->assertNull($listing->tiktok_category_id);

        // A listing with its own category keeps it over the group's.
        $own = $this->seedProduct('Own one', 'OW-1');
        $ownListing = TikTokListing::query()->create(['product_id' => $own, 'tiktok_category_id' => '900777']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $grp->id, 'product_id' => $own, 'sync_status' => 'pending']);
        $filled = $inherit->fill($ownListing, $inherit->forProducts([$own])[$own] ?? null);
        $this->assertSame('900777', $filled['listing']->tiktok_category_id);
        $this->assertSame([], $filled['inherited']);
    }
}
