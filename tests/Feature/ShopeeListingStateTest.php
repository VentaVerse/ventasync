<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\Listings\ListingState;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\Shopee\ShopeeListingStates;
use Extensions\shopee\Services\Shopee\ShopeeLiveListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B of the channels rebuild (#184): ONE computation answers "where
 * does this product stand on Shopee?", and every surface renders it.
 * Before this, eleven status vocabularies and three disagreeing label maps
 * meant pages could not agree; a user can only trust a system whose
 * screens agree with each other.
 */
class ShopeeListingStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);
    }

    private function product(string $sku, string $image = 'catalog/x.png', ?string $modified = null): int
    {
        $pfx = (string) config('catalog.prefix');

        return (int) DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => $image, 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now()->subDays(30), 'date_modified' => $modified ?? now()->subDays(30),
            'date_available' => now()->subDays(30),
        ]);
    }

    private function readyListing(int $productId, array $extra = []): ShopeeListing
    {
        ShopeeCategoryTemplate::query()->firstOrCreate(
            ['category_id' => 100139, 'region' => 'production'],
            ['attributes' => ['attribute_list' => []], 'fetched_at' => now()]
        );

        return ShopeeListing::query()->create(array_merge([
            'product_id' => $productId,
            'shopee_category_id' => 100139,
            'logistic_ids' => [8003],
        ], $extra));
    }

    private function states(array $ids): array
    {
        return app(ShopeeListingStates::class)->forProducts($ids);
    }

    public function test_the_truth_table_from_not_listed_to_attention(): void
    {
        // Not listed, nothing configured: not ready, with CODED gaps.
        $bare = $this->product('BARE', '');
        $s = $this->states([$bare])[$bare];
        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertFalse($s->ready);
        $codes = array_column($s->missing, 'code');
        $this->assertContains(ListingState::GAP_CATEGORY, $codes);
        $this->assertContains(ListingState::GAP_COURIERS, $codes);
        $this->assertContains(ListingState::GAP_IMAGE, $codes);
        $this->assertStringContainsString('a Shopee category', $s->missingSummary());

        // Not listed with settings, sheet and image: ready.
        $ready = $this->product('READY');
        $this->readyListing($ready);
        $s = $this->states([$ready])[$ready];
        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertTrue($s->ready, $s->missingSummary());

        // Linked, mirror never read: unknown, never a guess.
        $unknown = $this->product('UNK');
        ShopeeProductLink::create(['product_id' => $unknown, 'shopee_item_id' => 111, 'sku' => 'UNK']);
        $s = $this->states([$unknown])[$unknown];
        $this->assertSame(ListingState::UNKNOWN, $s->state);
        $this->assertSame('Not checked yet', $s->label());

        // The observed statuses, one state each.
        foreach ([
            'NORMAL' => [ListingState::LIVE, 'Live', 'success'],
            'UNLIST' => [ListingState::INACTIVE, 'Unpublished', 'neutral'],
            'REVIEWING' => [ListingState::REVIEWING, 'Under review', 'warning'],
            'BANNED' => [ListingState::ATTENTION, 'Violation', 'danger'],
            'MISSING' => [ListingState::ATTENTION, 'Not found on Shopee', 'danger'],
        ] as $raw => [$state, $label, $tone]) {
            $pid = $this->product('S' . $raw);
            ShopeeProductLink::create([
                'product_id' => $pid, 'shopee_item_id' => $pid, 'sku' => 'S' . $raw,
                'live_status' => $raw, 'live_checked_at' => now(),
            ]);
            $s = $this->states([$pid])[$pid];
            $this->assertSame($state, $s->state, $raw);
            $this->assertSame($label, $s->label(), $raw);
            $this->assertSame($tone, $s->tone(), $raw);
        }
    }

    /**
     * One latest error per product (the owner, 2026-09-05): a refusal from any
     * push lands on the listing record and every surface tells it, until
     * a push succeeds and clears it.
     */
    public function test_the_last_push_refusal_is_told_on_every_surface_until_a_push_succeeds(): void
    {
        $pid = $this->product('IMG');
        $this->readyListing($pid);

        ShopeeListing::recordPushFailure($pid, 'The number of product images must be between 1 and 9. Please adjust it.');
        $s = $this->states([$pid])[$pid];
        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertTrue($s->ready, 'the refusal is not a readiness gap');
        $this->assertCount(1, $s->reasons);
        $this->assertStringContainsString('Last push', $s->reasons[0]);
        $this->assertStringContainsString('failed: The number of product images must be between 1 and 9', $s->reasons[0]);

        // The workbench draws it on the row, in the shared Last sync column.
        $group = \App\Models\Admin\UserGroup::create(['name' => 'Shopee push readers']);
        $group->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all());
        $user = \App\Models\User::factory()->create(['user_group_id' => $group->id]);
        $html = $this->actingAs($user)->get(route('ext.shopee.products.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Last sync', $html);
        $this->assertStringContainsString('between 1 and 9', $html);

        // The one answer both pages draw: the refusal wins over any sync time.
        $sync = app(ShopeeListingStates::class)->lastSync([$pid])[$pid];
        $this->assertSame('failed', $sync['tone']);
        $this->assertStringContainsString('Last push', $sync['headline']);
        $this->assertStringContainsString('between 1 and 9', $sync['detail']);

        // A linked product carries it too, beside its live state.
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 777, 'sku' => 'IMG', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $this->assertStringContainsString('between 1 and 9', implode(' ', $this->states([$pid])[$pid]->reasons));

        ShopeeListing::clearPushFailure($pid);
        $this->assertSame([], $this->states([$pid])[$pid]->reasons);
        $this->assertNull(ShopeeListing::query()->where('product_id', $pid)->value('last_push_error'));

        // With no refusal, the column is the last successful sync: the newest
        // of a stock or price sync on the link and the listing push.
        ShopeeProductLink::query()->where('product_id', $pid)->update(['last_synced_at' => now()->subMinutes(5), 'last_sync_action' => 'stock', 'last_sync_ok' => true]);
        ShopeeListing::query()->where('product_id', $pid)->update(['last_pushed_at' => now()->subHours(3)]);
        $sync = app(ShopeeListingStates::class)->lastSync([$pid])[$pid];
        $this->assertSame('ok', $sync['tone']);
        $this->assertStringContainsString('5 minutes ago', $sync['headline']);
        $this->assertSame('stock', $sync['what']);

        // A failed stock sync is told the same way, and a never-synced product says so.
        ShopeeProductLink::query()->where('product_id', $pid)->update(['last_sync_ok' => false, 'last_sync_error_message' => 'Stock update refused: item is under review.']);
        $sync = app(ShopeeListingStates::class)->lastSync([$pid])[$pid];
        $this->assertSame('failed', $sync['tone']);
        $this->assertStringContainsString('Stock sync', $sync['headline']);
        $never = $this->product('NEVER');
        $this->assertSame('none', app(ShopeeListingStates::class)->lastSync([$never])[$never]['tone']);
    }

    public function test_a_push_sends_at_most_nine_images_main_first(): void
    {
        $pid = $this->product('MANY', 'catalog/main.png');
        $pfx = (string) config('catalog.prefix');
        for ($i = 1; $i <= 12; $i++) {
            \Illuminate\Support\Facades\DB::table($pfx . 'product_image')->insert(['product_id' => $pid, 'image' => "catalog/g{$i}.png", 'sort_order' => $i]);
        }
        $product = \Illuminate\Support\Facades\DB::table($pfx . 'product')->where('product_id', $pid)->first();

        $paths = app(\Extensions\shopee\Services\Shopee\ShopeeItemCreate::class)->catalogImagePaths($pfx, $product);

        $this->assertCount(9, $paths, 'Shopee refuses more than nine');
        $this->assertSame('catalog/main.png', $paths[0], 'the main image leads');
        $this->assertSame('catalog/g8.png', $paths[8]);
    }

    public function test_live_and_out_of_date_are_different_facts_and_both_are_told(): void
    {
        // Pushed, then the catalogue moved: live, not in sync.
        $moved = $this->product('MOVED', 'catalog/x.png', now()->format('Y-m-d H:i:s'));
        $this->readyListing($moved, ['last_pushed_at' => now()->subDay()]);
        ShopeeProductLink::create(['product_id' => $moved, 'shopee_item_id' => 900, 'sku' => 'MOVED',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $s = $this->states([$moved])[$moved];
        $this->assertSame(ListingState::LIVE, $s->state);
        $this->assertFalse($s->inSync);
        $this->assertSame('Catalogue changed', $s->driftLabel());
        $this->assertStringContainsString('changed after the last push', $s->reasons[0]);

        // Pushed after the last catalogue change: in sync, no chip.
        $steady = $this->product('STEADY', 'catalog/x.png', now()->subDays(3)->format('Y-m-d H:i:s'));
        $this->readyListing($steady, ['last_pushed_at' => now()->subDay()]);
        ShopeeProductLink::create(['product_id' => $steady, 'shopee_item_id' => 901, 'sku' => 'STEADY',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $s = $this->states([$steady])[$steady];
        $this->assertTrue($s->inSync);
        $this->assertNull($s->driftLabel());

        // Linked but never pushed from here (matched in): nothing to
        // compare, and the engine says so instead of guessing.
        $adopted = $this->product('ADOPTED');
        ShopeeProductLink::create(['product_id' => $adopted, 'shopee_item_id' => 902, 'sku' => 'ADOPTED',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $this->assertNull($this->states([$adopted])[$adopted]->inSync);
    }

    public function test_a_product_with_many_rows_gets_one_state_and_the_worst_wins(): void
    {
        $pid = $this->product('MULTI');
        foreach ([['shopee_model_id' => 1, 'live_status' => 'NORMAL'], ['shopee_model_id' => 2, 'live_status' => 'BANNED']] as $row) {
            ShopeeProductLink::create(array_merge([
                'product_id' => $pid, 'shopee_item_id' => 950, 'sku' => 'MULTI', 'live_checked_at' => now(),
            ], $row));
        }

        $s = $this->states([$pid])[$pid];
        $this->assertSame(ListingState::ATTENTION, $s->state, 'one banned variation IS the product\'s situation');
        $this->assertSame('Violation', $s->label());
    }

    public function test_a_push_writes_the_mirror_through_with_shopees_own_answer(): void
    {
        // The write-through asks Shopee, never assumes: a fresh item can be
        // NORMAL or REVIEWING and only Shopee knows which.
        $fake = new class extends ShopeeClient {
            public function __construct() {}
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                return ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => [
                    ['item_id' => 990077, 'item_status' => 'REVIEWING'],
                ]]]];
            }
        };

        $pid = $this->product('PUSHED');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 990077, 'sku' => 'PUSHED']);

        (new ShopeeLiveListing($fake))->confirm(['mode' => 'production', 'partner_id' => 1, 'partner_key' => 'k', 'access_token' => 't', 'shop_id' => 2], [990077]);

        $link = ShopeeProductLink::query()->where('shopee_item_id', 990077)->first();
        $this->assertSame('REVIEWING', $link->live_status);
        $this->assertNotNull($link->live_checked_at);
        $this->assertSame(ListingState::REVIEWING, $this->states([$pid])[$pid]->state);
    }

    public function test_the_write_through_swallows_a_failed_read_because_the_push_already_succeeded(): void
    {
        $fake = new class extends ShopeeClient {
            public function __construct() {}
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                throw new \RuntimeException('marketplace down');
            }
        };

        $pid = $this->product('BLIND');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 990088, 'sku' => 'BLIND']);

        (new ShopeeLiveListing($fake))->confirm(['mode' => 'production', 'partner_id' => 1, 'partner_key' => 'k', 'access_token' => 't', 'shop_id' => 2], [990088]);

        $this->assertNull(ShopeeProductLink::query()->where('shopee_item_id', 990088)->value('live_status'), 'stays honestly blank');
    }

    public function test_the_summary_counts_trouble_without_walking_every_product(): void
    {
        // Two attention (banned + missing), one drifted live, one clean live.
        foreach ([
            ['BANNED', 1101], ['MISSING', 1102],
        ] as [$status, $item]) {
            $pid = $this->product('SUM' . $item);
            ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => $item, 'sku' => 'SUM' . $item,
                'live_status' => $status, 'live_checked_at' => now()]);
        }

        $drift = $this->product('SUMDRIFT', 'catalog/x.png', now()->format('Y-m-d H:i:s'));
        $this->readyListing($drift, ['last_pushed_at' => now()->subDay()]);
        ShopeeProductLink::create(['product_id' => $drift, 'shopee_item_id' => 1103, 'sku' => 'SUMDRIFT',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);

        $clean = $this->product('SUMCLEAN', 'catalog/x.png', now()->subDays(9)->format('Y-m-d H:i:s'));
        $this->readyListing($clean, ['last_pushed_at' => now()->subDay()]);
        ShopeeProductLink::create(['product_id' => $clean, 'shopee_item_id' => 1104, 'sku' => 'SUMCLEAN',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);

        $sum = app(ShopeeListingStates::class)->summary();
        $this->assertSame(2, $sum['attention']);
        $this->assertSame(1, $sum['drift']);
    }

    public function test_the_dashboard_chip_carries_the_listing_troubles_in_words(): void
    {
        $pid = $this->product('DASH');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 1201, 'sku' => 'DASH',
            'live_status' => 'BANNED', 'live_checked_at' => now()]);

        $data = (new \Extensions\shopee\ShopeeExtension(app()))->dashboardData();
        $this->assertSame('1 listing needs attention', $data['shopeeSyncStatus']->listing_flags);
        $this->assertSame(1, $data['shopeeSyncStatus']->listing_attention);

        // No troubles, no line: absence is the ordinary state.
        ShopeeProductLink::query()->update(['live_status' => 'NORMAL']);
        $data = (new \Extensions\shopee\ShopeeExtension(app()))->dashboardData();
        $this->assertNull($data['shopeeSyncStatus']->listing_flags);
    }

    /**
     * A groupless, never-pushed product the store carries is visible by
     * default. Phase C (#184) made the default view the whole catalogue so
     * a new user's first product could never be invisible (the CedCommerce
     * worldview had hidden it until a group claimed it); #201 (2026-09-05)
     * made the view THIS STORE's own list - so the product is on the store
     * first, with the blank record Add products leaves, and a group is still
     * not required for it to show or for not_listed to find it.
     */
    public function test_a_groupless_never_pushed_product_on_the_store_is_visible_by_default(): void
    {
        $pid = $this->product('FIRSTEVER');
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'My very first product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        ShopeeListing::create(['product_id' => $pid]);

        $group = \App\Models\Admin\UserGroup::create(['name' => 'Shopee viewers']);
        $group->permissions()->attach(
            \App\Models\Admin\Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all()
        );
        $user = \App\Models\User::factory()->create(['user_group_id' => $group->id]);

        $page = $this->actingAs($user)->get(route('ext.shopee.products.index'))->assertOk();
        $page->assertSee('My very first product');
        $page->assertSee('Not listed');

        // And the not_listed filter can actually find it now: the old
        // default whereIn used to intersect it away.
        $this->actingAs($user)->get(route('ext.shopee.products.index').'?sync_status=not_listed')
            ->assertOk()->assertSee('My very first product');
    }

    /**
     * From Phase B1 until this fix BOTH toggles were invisible on every row:
     * the label-map rewrite deleted $rowLiveStatus but the toggle forms still
     * keyed their hidden attribute on it, and an undefined variable hides
     * both. Actions now derive from the same state the badge wears (#188),
     * so only the applicable toggle exists at all.
     */
    public function test_the_row_menu_offers_only_the_applicable_toggle(): void
    {
        $live = $this->product('TGL-LIVE');
        ShopeeProductLink::create(['product_id' => $live, 'shopee_item_id' => 3001, 'sku' => 'TGL-LIVE', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $off = $this->product('TGL-OFF');
        ShopeeProductLink::create(['product_id' => $off, 'shopee_item_id' => 3002, 'sku' => 'TGL-OFF', 'live_status' => 'UNLIST', 'live_checked_at' => now()]);

        $group = \App\Models\Admin\UserGroup::create(['name' => 'Shopee togglers']);
        $group->permissions()->attach(
            \App\Models\Admin\Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all()
        );
        $user = \App\Models\User::factory()->create(['user_group_id' => $group->id]);

        // The bulk bar always carries both verbs for the selection, so the
        // assertions pin the row MENU's items specifically.
        $liveRow = $this->actingAs($user)->get(route('ext.shopee.products.index').'?q=TGL-LIVE')->assertOk();
        $liveRow->assertSee('x-menu__item">Delist from Shopee', false);
        $liveRow->assertDontSee('x-menu__item">Publish on Shopee', false);

        $offRow = $this->actingAs($user)->get(route('ext.shopee.products.index').'?q=TGL-OFF')->assertOk();
        $offRow->assertSee('x-menu__item">Publish on Shopee', false);
        $offRow->assertDontSee('x-menu__item">Delist from Shopee', false);
    }

    /** The group page offers the toggle the listings page offers (#188, the owner 2026-09-02). */
    public function test_the_group_page_offers_the_toggle_the_listing_page_offers(): void
    {
        $pid = $this->product('TGL-GRP');
        DB::table((string) config('catalog.prefix') . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Togglable in group', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 3003, 'sku' => 'TGL-GRP', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $grp = \Extensions\shopee\Models\ShopeeProductGroup::create(['name' => 'Toggle group', 'shopee_category_id' => 100139]);
        \Extensions\shopee\Models\ShopeeProductGroupProduct::create([
            'shopee_product_group_id' => $grp->id, 'product_id' => $pid,
            'shopee_item_id' => 3003, 'sync_status' => 'pushed',
        ]);

        $ug = \App\Models\Admin\UserGroup::create(['name' => 'Shopee group togglers']);
        $ug->permissions()->attach(
            \App\Models\Admin\Permission::whereIn('key', [
                'view_shopee/product_group', 'manage_shopee/product_group', 'manage_shopee/product',
            ])->pluck('id')->all()
        );
        $user = \App\Models\User::factory()->create(['user_group_id' => $ug->id]);

        $this->actingAs($user)->get(route('ext.shopee.product-groups.products', $grp->id))
            ->assertOk()->assertSee('Delist from Shopee');
    }

    public function test_shopee_answers_the_core_contract_every_channel_will_share(): void
    {
        $pid = $this->product('VIACORE');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 970, 'sku' => 'VIACORE',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);

        $sources = app(IntegrationRegistry::class)->listingStateSources();
        $this->assertNotEmpty($sources, 'the Shopee provider registers as a ListingStateSource');

        $states = collect($sources)->map(fn ($s) => $s->listingStates([$pid]))->first();
        $this->assertSame(ListingState::LIVE, $states[$pid]->state);
    }
}
