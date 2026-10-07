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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

        $id = (int) DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => $image, 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now()->subDays(30), 'date_modified' => $modified ?? now()->subDays(30),
            'date_available' => now()->subDays(30),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $id, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Product ' . $sku, 'description' => 'A description.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $id;
    }

    private function editCatalog(int $productId, ?string $name = null): void
    {
        $pfx = (string) config('catalog.prefix');
        if ($name !== null) {
            DB::table($pfx . 'product_description')->where('product_id', $productId)->update(['name' => $name]);
        }
        DB::table($pfx . 'product')->where('product_id', $productId)->update(['date_modified' => now()->addMinute()]);
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
        $bare = $this->product('BARE', '');
        $s = $this->states([$bare])[$bare];
        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertFalse($s->ready);
        $codes = array_column($s->missing, 'code');
        $this->assertContains(ListingState::GAP_CATEGORY, $codes);
        $this->assertContains(ListingState::GAP_COURIERS, $codes);
        $this->assertContains(ListingState::GAP_IMAGE, $codes);
        $this->assertStringContainsString('a Shopee category', $s->missingSummary());

        $ready = $this->product('READY');
        $this->readyListing($ready);
        $s = $this->states([$ready])[$ready];
        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertTrue($s->ready, $s->missingSummary());

        $unknown = $this->product('UNK');
        ShopeeProductLink::create(['product_id' => $unknown, 'shopee_item_id' => 111, 'sku' => 'UNK']);
        $s = $this->states([$unknown])[$unknown];
        $this->assertSame(ListingState::UNKNOWN, $s->state);
        $this->assertSame('Not checked yet', $s->label());

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

    public function test_the_last_push_refusal_is_told_on_every_surface_until_a_push_succeeds(): void
    {
        $pid = $this->product('IMG');
        $this->readyListing($pid);

        app(ShopeeListingStates::class)->recordOutcome($pid, 'The number of product images must be between 1 and 9. Please adjust it.');
        $s = $this->states([$pid])[$pid];
        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertTrue($s->ready, 'the refusal is not a readiness gap');
        $this->assertCount(1, $s->reasons);
        $this->assertStringContainsString('Last push', $s->reasons[0]);
        $this->assertStringContainsString('failed: The number of product images must be between 1 and 9', $s->reasons[0]);

        $group = \App\Models\Admin\UserGroup::create(['name' => 'Shopee push readers']);
        $group->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all());
        $user = \App\Models\User::factory()->create(['user_group_id' => $group->id]);
        $html = $this->actingAs($user)->get(route('ext.shopee.products.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<tr class="cc-err-row">', $html);
        $this->assertStringContainsString('between 1 and 9', $html);

        $this->assertStringContainsString('between 1 and 9', (string) app(ShopeeListingStates::class)->errors([$pid])[$pid]);

        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 777, 'sku' => 'IMG', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $this->assertStringContainsString('between 1 and 9', implode(' ', $this->states([$pid])[$pid]->reasons));

        app(ShopeeListingStates::class)->clearErrors([$pid]);
        $this->assertSame([], $this->states([$pid])[$pid]->reasons);
        $this->assertNull(ShopeeListing::query()->where('product_id', $pid)->value('last_push_error'));

        ShopeeProductLink::query()->where('product_id', $pid)->update(['last_synced_at' => now()->subMinutes(5), 'last_sync_action' => 'stock', 'last_sync_ok' => true]);
        ShopeeListing::query()->where('product_id', $pid)->update(['last_pushed_at' => now()->subHours(3)]);
        $this->assertNull(app(ShopeeListingStates::class)->errors([$pid])[$pid]);

        ShopeeProductLink::query()->where('product_id', $pid)->update(['last_sync_ok' => false, 'last_sync_error_message' => 'Stock update refused: item is under review.']);
        $this->assertSame('Stock update refused: item is under review.', app(ShopeeListingStates::class)->errors([$pid])[$pid]);

        ShopeeProductLink::query()->where('product_id', $pid)->update(['last_sync_error_message' => null]);
        $this->assertSame(\App\Integrations\Push\PushLedger::NO_REASON, app(ShopeeListingStates::class)->errors([$pid])[$pid]);

        $never = $this->product('NEVER');
        $this->assertNull(app(ShopeeListingStates::class)->errors([$never])[$never]);
    }

    public function test_a_variation_the_store_sells_but_its_item_lacks_is_an_error_until_switched_off(): void
    {
        $pid = $this->product('EXL110');
        foreach (['EXL110-1046', 'EXL110-0942', 'EXL110-1149'] as $i => $sku) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $pid, 'sku' => $sku, 'quantity' => 3, 'absolute_price' => 100, 'status' => 1, 'sort_order' => $i,
            ]);
        }
        $this->readyListing($pid);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 910, 'shopee_model_id' => 1, 'sku' => 'EXL110-1046', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $storeId = (int) ShopeeSetting::defaultStore()->id;
        \App\Integrations\Listings\ListingVariations::remember('shopee', $storeId, $pid, ['EXL110-1046', 'EXL110-0942']);

        $line = (string) app(ShopeeListingStates::class)->errors([$pid])[$pid];
        $this->assertStringContainsString('Not on Shopee:', $line);
        $this->assertStringContainsString('EXL110-1149', $line);
        $this->assertStringNotContainsString('EXL110-0942', $line);

        $group = \App\Models\Admin\UserGroup::create(['name' => 'Shopee missing readers']);
        $group->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all());
        $user = \App\Models\User::factory()->create(['user_group_id' => $group->id]);
        $html = $this->actingAs($user)->get(route('ext.shopee.products.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Not on Shopee:', $html);
        $this->assertStringContainsString('EXL110-1149', $html);

        DB::table(\App\Integrations\Listings\ListingVariations::TABLE)->insert([
            'channel' => 'shopee', 'store_id' => $storeId, 'product_id' => $pid, 'sku' => 'exl110-1149',
        ]);
        $this->assertNull(app(ShopeeListingStates::class)->errors([$pid])[$pid]);
    }

    public function test_a_push_sends_every_picture_that_is_on_main_first(): void
    {
        $pid = $this->product('MANY', 'catalog/main.png');
        $pfx = (string) config('catalog.prefix');
        for ($i = 1; $i <= 12; $i++) {
            \Illuminate\Support\Facades\DB::table($pfx . 'product_image')->insert(['product_id' => $pid, 'image' => "catalog/g{$i}.png", 'sort_order' => $i]);
        }
        $product = \Illuminate\Support\Facades\DB::table($pfx . 'product')->where('product_id', $pid)->first();
        $create = app(\Extensions\shopee\Services\Shopee\ShopeeItemCreate::class);

        $paths = $create->catalogImagePaths($product);

        $this->assertCount(13, $paths, 'nothing is trimmed on a store\'s behalf');
        $this->assertSame('catalog/main.png', $paths[0], 'the main image leads');

        $listing = ShopeeListing::query()->create([
            'product_id' => $pid,
            'image_off' => ['catalog/g2.png', 'catalog/g5.png'],
        ]);

        $sent = $create->catalogImagePaths($product, $listing->fresh());

        $this->assertCount(11, $sent);
        $this->assertNotContains('catalog/g2.png', $sent);
        $this->assertNotContains('catalog/g5.png', $sent);
        $this->assertSame('catalog/main.png', $sent[0], 'the main image still leads');
    }

    public function test_live_and_a_catalog_change_are_different_facts_and_both_are_told(): void
    {
        $moved = $this->product('MOVED');
        $this->readyListing($moved);
        ShopeeProductLink::create(['product_id' => $moved, 'shopee_item_id' => 900, 'sku' => 'MOVED',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $this->editCatalog($moved, 'Product MOVED, renamed');
        $s = $this->states([$moved])[$moved];
        $this->assertSame(ListingState::LIVE, $s->state);
        $this->assertFalse($s->inSync);
        $this->assertSame('Catalog change', $s->driftLabel());
        $this->assertSame([], $s->reasons, 'a Catalog change is a button, not a reason');

        $steady = $this->product('STEADY');
        $this->readyListing($steady);
        ShopeeProductLink::create(['product_id' => $steady, 'shopee_item_id' => 901, 'sku' => 'STEADY',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $this->editCatalog($steady);
        $s = $this->states([$steady])[$steady];
        $this->assertTrue($s->inSync);
        $this->assertNull($s->driftLabel());

        $unlisted = $this->product('UNLISTED');
        $this->readyListing($unlisted);
        $this->editCatalog($unlisted, 'Product UNLISTED, renamed');
        $this->assertFalse($this->states([$unlisted])[$unlisted]->inSync);

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
        foreach ([
            ['BANNED', 1101], ['MISSING', 1102],
        ] as [$status, $item]) {
            $pid = $this->product('SUM' . $item);
            ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => $item, 'sku' => 'SUM' . $item,
                'live_status' => $status, 'live_checked_at' => now()]);
        }

        $drift = $this->product('SUMDRIFT');
        $this->readyListing($drift);
        ShopeeProductLink::create(['product_id' => $drift, 'shopee_item_id' => 1103, 'sku' => 'SUMDRIFT',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $this->editCatalog($drift, 'Product SUMDRIFT, renamed');

        $clean = $this->product('SUMCLEAN');
        $this->readyListing($clean);
        ShopeeProductLink::create(['product_id' => $clean, 'shopee_item_id' => 1104, 'sku' => 'SUMCLEAN',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $this->editCatalog($clean);

        $sum = app(ShopeeListingStates::class)->summary();
        $this->assertSame(2, $sum['attention']);
        $this->assertSame(1, $sum['drift']);
        $this->assertSame([$drift], app(ShopeeListingStates::class)->productIdsIn('drift'), 'the count and the rows agree');
    }

    public function test_the_dashboard_chip_carries_the_listing_troubles_in_words(): void
    {
        $pid = $this->product('DASH');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 1201, 'sku' => 'DASH',
            'live_status' => 'BANNED', 'live_checked_at' => now()]);

        $data = (new \Extensions\shopee\ShopeeExtension(app()))->dashboardData();
        $flags = $data['shopeeSyncStatus']->listing_flags;

        $this->assertCount(1, $flags);
        $this->assertSame('1 listing needs attention', $flags[0]['text']);
        $this->assertSame(1, $data['shopeeSyncStatus']->listing_attention);

        $this->assertStringContainsString('state=attention', (string) $flags[0]['href']);
        $this->assertStringContainsString('/shopee/', (string) $flags[0]['href']);

        ShopeeProductLink::query()->update(['live_status' => 'NORMAL']);
        $data = (new \Extensions\shopee\ShopeeExtension(app()))->dashboardData();
        $this->assertSame([], $data['shopeeSyncStatus']->listing_flags);
    }

    public function test_the_listings_page_filters_to_the_figure_the_dashboard_counted(): void
    {
        $troubled = $this->product('SICK');
        ShopeeProductLink::create(['product_id' => $troubled, 'shopee_item_id' => 1301, 'sku' => 'SICK',
            'live_status' => 'BANNED', 'live_checked_at' => now()]);

        $fine = $this->product('WELL');
        ShopeeProductLink::create(['product_id' => $fine, 'shopee_item_id' => 1302, 'sku' => 'WELL',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);

        $engine = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class);
        $this->assertSame([$troubled], $engine->productIdsIn('attention'),
            'The rows behind the figure must be the rows the figure counted.');
        $this->assertSame(1, $engine->summary()['attention']);

        $group = \App\Models\Admin\UserGroup::create(['name' => 'Shopee trouble readers']);
        $group->permissions()->attach(\App\Models\Admin\Permission::whereIn('key',
            ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all());
        $user = \App\Models\User::factory()->create(['user_group_id' => $group->id]);

        $page = $this->actingAs($user)
            ->get(route('ext.shopee.products.index', ['state' => 'attention']))->assertOk();

        $page->assertSee('Showing the 1 listing that needs attention.');
        $page->assertSee('Show all');
        $page->assertSee('SICK');
        $page->assertDontSee('WELL');
    }

    public function test_a_groupless_never_pushed_product_on_the_store_is_visible_by_default(): void
    {
        $pid = $this->product('FIRSTEVER');
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product_description')->where('product_id', $pid)->update([
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

        $this->actingAs($user)->get(route('ext.shopee.products.index').'?sync_status=not_listed')
            ->assertOk()->assertSee('My very first product');
    }

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

        $liveRow = $this->actingAs($user)->get(route('ext.shopee.products.index').'?q=TGL-LIVE')->assertOk();
        $liveRow->assertSee('x-menu__item">Delist from Shopee', false);
        $liveRow->assertDontSee('x-menu__item">Publish on Shopee', false);

        $offRow = $this->actingAs($user)->get(route('ext.shopee.products.index').'?q=TGL-OFF')->assertOk();
        $offRow->assertSee('x-menu__item">Publish on Shopee', false);
        $offRow->assertDontSee('x-menu__item">Delist from Shopee', false);
    }

    public function test_the_group_page_offers_the_toggle_the_listing_page_offers(): void
    {
        $pid = $this->product('TGL-GRP');
        DB::table((string) config('catalog.prefix') . 'product_description')->where('product_id', $pid)->update([
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

    private function fakeShopee(array $answers): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($answers) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return Http::response($answers[$path] ?? ['error' => '', 'message' => '', 'response' => []], 200);
        });
    }

    private function operator(): \App\Models\User
    {
        $group = \App\Models\Admin\UserGroup::create(['name' => 'Shopee outcome operators']);
        $group->permissions()->attach(\App\Models\Admin\Permission::where('key', 'like', '%_shopee/%')->pluck('id')->all());

        return \App\Models\User::factory()->create(['user_group_id' => $group->id]);
    }

    private function line(int $productId): ?string
    {
        return app(ShopeeListingStates::class)->errors([$productId])[$productId];
    }

    private function linkedProduct(string $sku, int $itemId): int
    {
        $pid = $this->product($sku);
        $this->readyListing($pid);
        ShopeeProductLink::create([
            'product_id' => $pid, 'shopee_item_id' => $itemId, 'sku' => $sku,
            'live_status' => 'NORMAL', 'live_checked_at' => now(),
        ]);

        return $pid;
    }

    public function test_a_refused_update_is_the_line_until_a_stock_sync_goes_through(): void
    {
        $pid = $this->linkedProduct('UPD', 5001);
        $this->fakeShopee([
            '/api/v2/product/update_item' => ['error' => 'error_param', 'message' => 'Brand information required'],
            '/api/v2/product/update_stock' => ['error' => '', 'message' => '', 'response' => ['failure_list' => []]],
        ]);
        $user = $this->operator();

        $this->actingAs($user)->post(route('ext.shopee.listings.push_update', $pid))->assertSessionHas('error');
        $this->assertSame('Update failed: Brand information required', $this->line($pid));

        $this->actingAs($user)->post(route('ext.shopee.products.sync_quantity', $pid))->assertSessionHas('status');
        $this->assertNull($this->line($pid));
        $this->assertNull(ShopeeListing::query()->where('product_id', $pid)->value('last_push_error'));
    }

    public function test_a_stored_push_error_survives_a_check_and_a_refresh_and_clears_on_the_next_stock_push(): void
    {
        $pid = $this->linkedProduct('KEEP', 5002);
        app(ShopeeListingStates::class)->recordOutcome($pid, 'Price is out of range.');
        $this->fakeShopee([
            '/api/v2/product/get_item_base_info' => ['error' => '', 'message' => '', 'response' => ['item_list' => [
                ['item_id' => 5002, 'item_status' => 'NORMAL', 'item_sku' => 'KEEP'],
            ]]],
            '/api/v2/product/get_item_list' => ['error' => '', 'message' => '', 'response' => [
                'item' => [['item_id' => 5002, 'item_status' => 'NORMAL']], 'has_next_page' => false, 'total_count' => 1,
            ]],
            '/api/v2/product/update_stock' => ['error' => '', 'message' => '', 'response' => ['failure_list' => []]],
        ]);
        $user = $this->operator();

        $this->actingAs($user)->post(route('ext.shopee.products.check'), ['product_ids' => [$pid]])->assertRedirect();
        $this->assertSame(1, ShopeeProductLink::query()->where('product_id', $pid)->count(), 'the check found the item');
        $this->assertSame('Price is out of range.', $this->line($pid), 'a check that finds the item leaves the failure standing');

        $this->artisan('shopee:refresh-listing-status');
        $this->assertSame('Price is out of range.', $this->line($pid), 'so does the scheduled refresh');

        $this->actingAs($user)->post(route('ext.shopee.products.sync_quantity', $pid))->assertSessionHas('status');
        $this->assertNull($this->line($pid));
    }

    public function test_a_check_that_finds_the_item_gone_sets_the_line(): void
    {
        $pid = $this->linkedProduct('GONE', 5003);
        $this->fakeShopee([
            '/api/v2/product/get_item_base_info' => ['error' => '', 'message' => '', 'response' => ['item_list' => []]],
            '/api/v2/product/get_item_list' => ['error' => '', 'message' => '', 'response' => ['item' => [], 'has_next_page' => false]],
        ]);

        $this->actingAs($this->operator())->post(route('ext.shopee.products.check'), ['product_ids' => [$pid]])->assertRedirect();

        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $pid)->count());
        $this->assertSame('Shopee item 5003 is no longer on Shopee.', $this->line($pid));
    }

    public function test_unlinking_clears_the_line(): void
    {
        $pid = $this->linkedProduct('UNL', 5004);
        app(ShopeeListingStates::class)->recordOutcome($pid, 'Update failed: Brand information required');
        ShopeeProductLink::query()->where('product_id', $pid)
            ->update(['last_sync_ok' => false, 'last_synced_at' => now(), 'last_sync_error_message' => 'Stock refused.']);
        $this->assertNotNull($this->line($pid));

        $this->actingAs($this->operator())->post(route('ext.shopee.products.unlink', $pid))->assertSessionHas('status');

        $this->assertNull($this->line($pid));
        $this->assertNull(ShopeeListing::query()->where('product_id', $pid)->value('last_push_error'));
        $this->assertSame([], app(ShopeeListingStates::class)->erroredProductIds());
    }

    public function test_the_error_filter_lists_exactly_the_rows_that_show_an_error_line(): void
    {
        $missing = $this->product('MISSVAR');
        foreach (['MISSVAR-A', 'MISSVAR-B'] as $i => $sku) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $missing, 'sku' => $sku, 'quantity' => 3, 'absolute_price' => 100, 'status' => 1, 'sort_order' => $i,
            ]);
        }
        $this->readyListing($missing);
        ShopeeProductLink::create(['product_id' => $missing, 'shopee_item_id' => 6001, 'shopee_model_id' => 1, 'sku' => 'MISSVAR-A',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $storeId = (int) ShopeeSetting::defaultStore()->id;
        \App\Integrations\Listings\ListingVariations::remember('shopee', $storeId, $missing, ['MISSVAR-A']);

        $refused = $this->linkedProduct('PUSHERR', 6002);
        $clean = $this->linkedProduct('CLEANROW', 6003);
        $states = app(ShopeeListingStates::class);
        $states->recordOutcome($refused, 'Push failed: Category is invalid.');

        $this->assertSame([$missing, $refused], $states->erroredProductIds());

        $user = $this->operator();
        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'error']))->assertOk()
            ->assertSee('MISSVAR')->assertSee('PUSHERR')->assertDontSee('CLEANROW')
            ->assertSee('products match: Push failed', false);

        $grp = DB::table('shopee_product_groups')->insertGetId([
            'shopee_setting_id' => $storeId, 'name' => 'Outcome group', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$missing, $refused, $clean] as $p) {
            DB::table('shopee_product_group_products')->insert(['shopee_product_group_id' => $grp, 'product_id' => $p]);
        }
        $this->actingAs($user)->get(route('ext.shopee.product-groups.products', ['id' => $grp, 'sync_status' => 'error']))->assertOk()
            ->assertSee('MISSVAR')->assertSee('PUSHERR')->assertDontSee('CLEANROW')
            ->assertSee('>Has an error</option>', false);
        $this->actingAs($user)->get(route('ext.shopee.product-groups.products', $grp))->assertOk()
            ->assertSee('have an error.');

        $states->recordOutcome($refused, null);
        $this->assertSame([$missing], $states->erroredProductIds());
        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'error']))->assertOk()
            ->assertSee('MISSVAR')->assertDontSee('PUSHERR');
    }

    public function test_a_listed_product_that_needs_details_is_found_under_listed(): void
    {
        $this->linkedProduct('LISTOK', 7001);

        $gap = $this->product('LISTGAP');
        DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $gap)->update(['weight' => 0]);
        $this->readyListing($gap);
        ShopeeProductLink::create([
            'product_id' => $gap, 'shopee_item_id' => 7002, 'sku' => 'LISTGAP',
            'live_status' => 'NORMAL', 'live_checked_at' => now(),
        ]);
        $this->assertFalse($this->states([$gap])[$gap]->ready, 'the row itself reads Needs details');

        $unlisted = $this->product('NOTPUSHED', '');
        ShopeeListing::query()->create(['product_id' => $unlisted]);

        $user = $this->operator();
        $page = $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'uploaded']))->assertOk();
        $page->assertSee('LISTGAP')->assertSee('LISTOK')->assertDontSee('NOTPUSHED')
            ->assertSee('Needs details')->assertSee('class="x-segment-bar"', false);

        foreach (['listed_not_ready', 'listed_ready'] as $retired) {
            $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => $retired]))->assertOk()
                ->assertSee('LISTOK')->assertSee('LISTGAP')->assertDontSee('NOTPUSHED');
        }

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'uploaded', 'shopee_tab' => 'unlisted']))
            ->assertOk()->assertDontSee('LISTGAP')->assertSee('class="x-segment-bar"', false);
    }

    public function test_the_item_id_opens_the_live_listing_on_shopee(): void
    {
        $this->linkedProduct('LIVELINK', 7003);
        $this->product('NOLINK');

        $this->actingAs($this->operator())->get(route('ext.shopee.products.index'))->assertOk()
            ->assertSee('href="https://shopee.ph/product/2002/7003" target="_blank" rel="noopener">Shopee 7003', false)
            ->assertSee('x-extlink', false);
    }

    public function test_an_outcome_lands_on_the_store_it_was_about(): void
    {
        $one = ShopeeSetting::defaultStore();
        $two = ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 3003,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production', 'store_name' => 'Second',
        ]);
        $pid = $this->product('TWOSTORE');
        $states = app(ShopeeListingStates::class);

        $states->forStore($two)->recordOutcome($pid, 'Refused on the second shop.');
        $this->assertNull($states->forStore($one)->errors([$pid])[$pid]);
        $this->assertSame('Refused on the second shop.', $states->forStore($two)->errors([$pid])[$pid]);
        $this->assertSame([], $states->forStore($one)->erroredProductIds());
        $this->assertSame([$pid], $states->forStore($two)->erroredProductIds());

        $states->forStore($one)->clearErrors([$pid]);
        $this->assertSame('Refused on the second shop.', $states->forStore($two)->errors([$pid])[$pid]);

        $states->forStore($two)->clearErrors([$pid]);
        $this->assertNull($states->forStore($two)->errors([$pid])[$pid]);
    }
}
