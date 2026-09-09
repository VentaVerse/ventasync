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

/**
 * Phase C of the channels rebuild (#184): Lazada answers "where does this
 * product stand?" through the same ListingState vocabulary Shopee earned
 * in Phase B - one engine, rendered everywhere, never guessed. And the
 * paths that change the marketplace link (delete, unlink) stop leaving a
 * stale mirror behind.
 */
class LazadaListingStateTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake marketplace answers, keyed by api path. Closures get the params. */
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

    /** Lazada's raw words map onto the one vocabulary, with Lazada's own attention labels. */
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
            '' => [ListingState::UNKNOWN, 'Not checked yet'], // never checked: live_status null
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

    /** A product with no lazada row at all is not_listed, with coded gaps from the catalogue. */
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

    /** A row never uploaded answers readiness from its own settings, not the bare catalogue. */
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

    /** Drift is a trichotomy: in sync, behind the catalogue, or honestly unknowable. */
    public function test_drift_true_false_and_null(): void
    {
        $pfx = (string) config('catalog.prefix');

        $inSync = $this->seedProduct('In sync', 'DR-1');
        LazadaProduct::create([
            'product_id' => $inSync, 'lazada_item_id' => '801',
            'live_status' => 'active', 'live_checked_at' => now(), 'last_pushed_at' => now(),
        ]);

        $behind = $this->seedProduct('Behind', 'DR-2');
        LazadaProduct::create([
            'product_id' => $behind, 'lazada_item_id' => '802',
            'live_status' => 'active', 'live_checked_at' => now(), 'last_pushed_at' => now()->subDays(5),
        ]);
        DB::table($pfx . 'product')->where('product_id', $behind)->update(['date_modified' => now()]);

        $neverPushed = $this->seedProduct('Matched in', 'DR-3');
        LazadaProduct::create([
            'product_id' => $neverPushed, 'lazada_item_id' => '803',
            'live_status' => 'active', 'live_checked_at' => now(),
        ]);

        $states = $this->engine()->forProducts([$inSync, $behind, $neverPushed]);

        $this->assertTrue($states[$inSync]->inSync);
        $this->assertNull($states[$inSync]->driftLabel());

        $this->assertFalse($states[$behind]->inSync);
        $this->assertSame('Catalogue changed', $states[$behind]->driftLabel());

        $this->assertNull($states[$neverPushed]->inSync, 'never pushed from here: nothing to compare');
    }

    /** Deleting from Lazada must void the mirror, not leave it wearing the old status. */
    public function test_delete_from_lazada_voids_the_mirror(): void
    {
        $productId = $this->seedProduct('Doomed', 'DE-1');
        $listing = LazadaProduct::create([
            'product_id' => $productId, 'lazada_item_id' => '701',
            'live_status' => 'active', 'live_checked_at' => now(),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.delete_lazada', $listing->product_id))
            ->assertRedirect();

        $listing->refresh();
        $this->assertNull($listing->lazada_item_id);
        $this->assertNull($listing->live_status, 'the link is gone; a kept status would be stale forever');
        $this->assertNull($listing->live_checked_at);
    }

    /** Unlinking cuts the link, so the mirror that described it goes too. */
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

    /** The dashboard summary counts by aggregate, and ignores rows no longer linked. */
    public function test_summary_counts_attention_and_drift(): void
    {
        $pfx = (string) config('catalog.prefix');

        $rejected = $this->seedProduct('Rejected', 'SM-1');
        LazadaProduct::create(['product_id' => $rejected, 'lazada_item_id' => '601', 'live_status' => 'rejected', 'live_checked_at' => now()]);

        $vanished = $this->seedProduct('Vanished', 'SM-2');
        LazadaProduct::create(['product_id' => $vanished, 'lazada_item_id' => '602', 'live_status' => 'missing', 'live_checked_at' => now()]);

        $behind = $this->seedProduct('Behind', 'SM-3');
        LazadaProduct::create(['product_id' => $behind, 'lazada_item_id' => '603', 'live_status' => 'active', 'live_checked_at' => now(), 'last_pushed_at' => now()->subDays(5)]);
        DB::table($pfx . 'product')->where('product_id', $behind)->update(['date_modified' => now()]);

        // Unlinked leftovers must not count: the trouble left with the link.
        $unlinked = $this->seedProduct('Unlinked leftover', 'SM-4');
        LazadaProduct::create(['product_id' => $unlinked, 'lazada_item_id' => '604', 'live_status' => 'rejected', 'live_checked_at' => now(), 'unlinked_at' => now()]);

        $sum = $this->engine()->summary();
        $this->assertSame(2, $sum['attention']);
        $this->assertSame(1, $sum['drift']);
    }

    /** The listing troubles ride the dashboard chip, same phrasing as Shopee's. */
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
        // The figure leads to the rows it counted (#240).
        $this->assertStringContainsString('state=attention', (string) $flags[0]['href']);
    }

    /**
     * Per-store split (#201, the owner 2026-09-05): a product with no listing on
     * this store is NOT on This store - it lives on Add products, the main
     * catalogue, where it is added to the store's list. This retires the
     * catalogue-first index, where a rowless product showed on the one table.
     */
    public function test_a_product_with_no_listing_lives_on_add_products_not_this_store(): void
    {
        $this->seedProduct('My very first product', 'FIRST-1');

        // This store is the store's own list; a rowless product is not on it.
        $this->actingAs($this->manager())->get(route('ext.lazada.products.index'))
            ->assertOk()->assertDontSee('My very first product');

        // Add products is the main catalogue - the product is here, ready to
        // be added to this store.
        $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.index', ['list' => 'add']))
            ->assertOk()->assertSee('My very first product')->assertSee('Add to store');
    }

    /** The group page offers the toggle the listings page offers (#188, the owner 2026-09-02). */
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
        // The row offers the one verb this inactive listing can take; the
        // bulk bar carries both (hidden until ticked rows say which applies).
        $page->assertSee('Activate on Lazada');
        $page->assertSee('data-bulk-verb="deactivate"', false);
        $page->assertSee('data-bulk-verb="activate"', false);
        $page->assertSee('data-bulk-field="product_ids[]"', false);
    }

    /** Core reaches Lazada's states through the same contract Shopee registered in Phase B. */
    public function test_the_engine_is_reachable_through_the_listing_state_contract(): void
    {
        $productId = $this->seedProduct('Contracted', 'CT-1');
        LazadaProduct::create(['product_id' => $productId, 'lazada_item_id' => '401', 'live_status' => 'active', 'live_checked_at' => now()]);

        $sources = $this->app->make(IntegrationRegistry::class)->listingStateSources();
        $lazada = collect($sources)->first(fn ($src) => $src instanceof LazadaExtension);

        $this->assertNotNull($lazada, 'LazadaExtension must register as a ListingStateSource');
        $this->assertSame(ListingState::LIVE, $lazada->listingStates([$productId])[$productId]->state);
    }

    /**
     * One latest push error per product (the owner, 2026-09-05): a refusal from
     * any push lands on the listing row and every surface tells it, until a
     * push succeeds; the Error column is the same answer everywhere.
     */
    public function test_the_last_push_refusal_and_the_error_column_are_one_answer(): void
    {
        $pid = $this->seedProduct('Refused one', 'RF-1');
        \Extensions\lazada\Models\LazadaProduct::query()->create(['product_id' => $pid]);

        \Extensions\lazada\Models\LazadaProduct::recordPushFailure($pid, 'Primary category is required before upload.');
        $states = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class);
        $s = $states->forProducts([$pid])[$pid];
        $this->assertStringContainsString('failed: Primary category is required', implode(' ', $s->reasons));
        $this->assertStringContainsString('Primary category is required', (string) $states->errors([$pid])[$pid]);

        \Extensions\lazada\Models\LazadaProduct::clearPushFailure($pid);
        \Extensions\lazada\Models\LazadaProduct::query()->where('product_id', $pid)->update(['last_synced_at' => now()->subMinutes(5), 'last_sync_action' => 'bulk_upload_to_lazada', 'last_sync_ok' => true]);
        $this->assertNull($states->errors([$pid])[$pid], 'a cleared refusal empties the Error column');
        $this->assertSame([], $states->forProducts([$pid])[$pid]->reasons);

        $never = $this->seedProduct('Never one', 'NV-1');
        $this->assertNull($states->errors([$never])[$never]);
    }

    public function test_a_group_is_a_preset_the_product_inherits(): void
    {
        // A group is a preset the product inherits (the owner, 2026-09-05): a
        // listing with no category of its own is judged, and pushed, with
        // its group's category and brand. Nothing is written to the row.
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

        // The row keeps its blanks: the group is a preset, not a write.
        $this->assertNull(\Extensions\lazada\Models\LazadaProduct::query()->find($listing->id)->primary_category_id);
        $this->assertNull($listing->primary_category_id);

        // A listing with its own category keeps it over the group's.
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
}
