<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The listing record (#97 phase 1) and the end of settings borrowing (#96).
 *
 * THE DEFECT THIS GUARDS AGAINST RETURNING: an individual push had no
 * settings of its own, so it borrowed the FIRST product group whose filter
 * matched - and with every live group's filters empty, primary-key order
 * chose the markup. It succeeded at the wrong price, silently. Now the push
 * form gives birth to the product's own listing record, one-click pushes
 * and price sync run on it, and a product without one is REFUSED by name,
 * never priced by accident.
 */
class ShopeeListingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{path: string, body: array}> */
    private array $sentPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        // Complete auth, plaintext: decrypted() falls back to plain values.
        ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);

        // Every marketplace call is captured, never sent. The image upload
        // path bypasses the client (raw Http facade), so it is faked at the
        // HTTP layer with the image_id shape push() reads.
        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->recordPayload($path, $body);

                return ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_id' => 990077]]];
            }
            public function signShop(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
            {
                return 'test-sign';
            }
            public function baseUrl(string $mode): string
            {
                return 'https://shopee.test';
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);

        \Illuminate\Support\Facades\Http::fake([
            'shopee.test/*' => \Illuminate\Support\Facades\Http::response([
                'response' => ['image_info' => ['image_id' => 'img-001']],
            ], 200),
        ]);

        // A real file for resolveLocalImagePath to find.
        @mkdir(public_path('image/catalog'), 0775, true);
        file_put_contents(public_path('image/catalog/__listing-test.png'), 'png');
    }

    protected function tearDown(): void
    {
        @unlink(public_path('image/catalog/__listing-test.png'));
        parent::tearDown();
    }

    public function recordPayload(string $path, array $body): void
    {
        $this->sentPayloads[] = ['path' => $path, 'body' => $body];
    }

    private int $managerSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Shopee listing managers ' . (++$this->managerSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product', 'manage_shopee/product_group', 'view_shopee/product_group'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(float $price = 300.0): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LST-1', 'sku' => 'LST-SKU-1', 'quantity' => 5, 'price' => $price,
            'status' => 1, 'image' => 'catalog/__listing-test.png', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);

        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Listing test product', 'description' => 'A perfectly real description.',
            'meta_title' => 'Listing test product', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    /** A catch-all group like all nine live ones: empty filters, loud markup. */
    private function seedCatchAllGroup(): ShopeeProductGroup
    {
        return ShopeeProductGroup::create([
            'name' => 'multi effects',
            'shopee_category_id' => 100013, 'logistic_ids' => [8003], 'markup_percent' => 50, 'markup_fixed' => 0,
        ]);
    }

    public function test_the_listing_editor_gives_birth_to_the_listing_record_and_pushes(): void
    {
        /* The push form died in the one-editor merge (2026-09-03): the
           listing editor is where a listing is born now, and Save and push
           is the one trip that creates the record and publishes it. The
           rule is stated directly (markup_fixed 70 over a 300 core), never
           a price (card #97 holds by construction). */
        $productId = $this->seedProduct(300.0);
        // Readiness demands the category's attribute sheet has been read
        // once; an empty sheet has no mandatory answers to give.
        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100200], ['attributes' => []]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.update', $productId), [
                'shopee_category_id' => 100200, 'logistic_ids' => [8003],
                'markup_fixed' => 70,
                'item_name' => 'Shopee-only name', 'description' => 'Shopee-only description.',
                'push_after' => 1,
            ]);

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertNotNull($listing, 'Save and push must create the listing record.');
        $this->assertSame(100200, (int) $listing->shopee_category_id);
        $this->assertSame([8003], $listing->logistic_ids);
        $this->assertSame(70.0, (float) $listing->markup_fixed);
        $this->assertSame('Shopee-only name', $listing->item_name);

        // The push half ran on what was just saved: rule over the core.
        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'Save and push must reach add_item.');
        $this->assertSame(370.0, (float) $add['body']['original_price']);
        $listing->refresh();
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertSame(370.0, (float) $listing->last_push_settings['price']);
    }

    public function test_one_click_push_refuses_a_product_with_no_listing_instead_of_borrowing(): void
    {
        $productId = $this->seedProduct();
        $this->seedCatchAllGroup(); // the trap: a catch-all with 50% markup exists

        $response = $this->actingAs($this->manager())
            ->from(route('ext.shopee.products.index'))
            ->post(route('ext.shopee.products.push_direct', $productId));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('a Shopee category', session('error'));

        // NOTHING was sent to Shopee, and no link exists: the catch-all
        // group's 50% markup priced nothing.
        $this->assertSame([], $this->sentPayloads);
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $productId)->count());
    }

    /**
     * MULTI-TICK PUSH, BACK ON THE LISTINGS PAGE (the owner, 2026-09-09).
     *
     * The shape the old bar could not manage: ten creates refused for ten
     * different reasons. Each product answers for itself, by name, and one
     * that is not ready does not stop the ones that are.
     */
    public function test_a_bulk_push_sends_what_is_ready_and_names_what_is_not(): void
    {
        $ready = $this->seedProduct(300.0);
        ShopeeListing::create([
            'product_id' => $ready, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'markup_percent' => 0, 'markup_fixed' => 70,
        ]);

        // No listing settings at all: not ready, and it must not stop the other.
        $notReady = $this->seedProduct(300.0);

        $response = $this->actingAs($this->manager())
            ->from(route('ext.shopee.products.index'))
            ->post(route('ext.shopee.products.bulk_push'), ['product_ids' => [$ready, $notReady]]);

        $response->assertRedirect();

        $adds = collect($this->sentPayloads)->where('path', '/api/v2/product/add_item');
        $this->assertCount(1, $adds, 'The ready product went up; the unready one did not.');

        $said = (string) session('status');
        $this->assertStringContainsString('1 pushed to Shopee', $said);
        $this->assertStringContainsString('1 not pushed', $said,
            'A batch that half-succeeds must say so; one outcome for ten products is the thing this replaced.');

        $this->assertSame(1, ShopeeProductLink::query()->where('product_id', $ready)->count());
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $notReady)->count());
    }

    public function test_a_bulk_push_with_nothing_ticked_is_refused(): void
    {
        $this->actingAs($this->manager())
            ->from(route('ext.shopee.products.index'))
            ->post(route('ext.shopee.products.bulk_push'), ['product_ids' => []])
            ->assertRedirect();

        $this->assertSame('No products selected.', session('error'));
        $this->assertSame([], $this->sentPayloads);
    }

    public function test_one_click_push_runs_on_the_listings_own_settings(): void
    {
        $productId = $this->seedProduct(300.0);
        $this->seedCatchAllGroup(); // must be ignored even though it exists

        ShopeeListing::create([
            'product_id' => $productId, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'markup_percent' => 0, 'markup_fixed' => 70,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push_direct', $productId));

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The push must reach add_item.');
        // The LISTING's rule (300 + 70), never the catch-all group's 50% (450).
        $this->assertSame(370.0, (float) $add['body']['original_price']);
        $this->assertSame(100200, (int) $add['body']['category_id']);

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertSame(370.0, (float) $listing->last_push_settings['price']);
    }

    /**
     * The one price rule (2026-09-07): a listing with no rule of its own
     * prices the way its group does, and its own rule wins once it has one.
     * The old refusal ("no listing price rule") is gone: a product in a
     * group always has a rule.
     */
    public function test_price_sync_prices_by_the_group_without_a_listing_rule_and_by_the_listing_with_one(): void
    {
        $productId = $this->seedProduct(300.0);
        $group = $this->seedCatchAllGroup();
        \Extensions\shopee\Models\ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $productId]);

        ShopeeProductLink::create([
            'product_id' => $productId, 'shopee_item_id' => 555, 'shopee_model_id' => null, 'sku' => 'LST-SKU-1',
        ]);

        // No listing at all: the group's 50% prices it.
        $this->actingAs($this->manager())
            ->from(route('ext.shopee.products.index'))
            ->post(route('ext.shopee.products.sync_price', $productId))
            ->assertSessionMissing('error');
        $update = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_price');
        $this->assertNotNull($update);
        $this->assertSame(450.0, (float) $update['body']['price_list'][0]['original_price'], 'base 300 + the group\'s 50%');

        // With a rule of its own, the listing's rule wins over the group's.
        ShopeeListing::create([
            'product_id' => $productId, 'markup_percent' => 0, 'markup_fixed' => 70,
        ]);
        $this->sentPayloads = [];

        $this->actingAs($this->manager())->post(route('ext.shopee.products.sync_price', $productId));

        $update = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_price');
        $this->assertNotNull($update);
        $this->assertSame(370.0, (float) $update['body']['price_list'][0]['original_price']);
    }

    public function test_a_group_page_tells_the_products_truth_not_its_own_memory(): void
    {
        /* the owner's GP-180 (QAS, 2026-08-24): this group's push failed on
         * couriers, then the product went up through its listing - and the
         * group page kept saying "Not pushed / Failed" while the Listings
         * page said Live, because it read its own pivot copy. The page now
         * reads the product's record: Listed, with the group's failed
         * ATTEMPT named as exactly that. */
        $productId = $this->seedProduct();
        $group = $this->seedCatchAllGroup();
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
            'sync_status' => 'error',
            'push_error' => 'At least one shipping channel must be enabled for the product.',
            'last_pushed_at' => now()->subMinutes(37),
        ]);
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LST-SKU-1',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'last_pushed_at' => now()->subMinutes(5), 'last_push_source' => 'listing',
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.product-groups.products', $group->id));

        $r->assertOk();
        $r->assertSee('990077');
        $r->assertSee('Listed');
        $r->assertDontSee('>Failed<', false);
        $r->assertDontSee('Not pushed');
        // The later push from the listing succeeded, so the product carries
        // no error line, and the group's older refusal is not its story.
        $r->assertDontSee('cc-err-row', false);
        $r->assertDontSee('shipping channel');
    }

    public function test_a_group_push_stamps_provenance_but_never_the_listings_own_settings(): void
    {
        $productId = $this->seedProduct(300.0);
        $group = $this->seedCatchAllGroup();
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.push', $group->id), ['ids' => [$productId]]);

        /* THE REGRESSION THIS PINS (found by the owner on QAS, 2026-08-24):
         * createOnShopee was extracted out of push() and left the
         * $logisticIds definition behind, so every group push sent
         * "logistic_info": [] and Shopee refused with
         * product.error_invalid_logistic_info. The group's couriers must
         * ride the payload. */
        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The group push must reach add_item.');
        $this->assertSame(
            [['logistic_id' => 8003, 'enabled' => true]],
            $add['body']['logistic_info'],
            'The GROUP\'s couriers go with a group push.'
        );

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertNotNull($listing, 'A group push must leave an auditable provenance row. ');
        $this->assertSame('group:multi effects', $listing->last_push_source);
        $this->assertSame(50.0, (float) $listing->last_push_settings['markup_percent']);

        // And the listing's OWN settings stay untouched: a group push is not
        // an edit of the listing (the owner's settled rule, card #104).
        $this->assertNull($listing->shopee_category_id);
        $this->assertNull($listing->markup_fixed);
        $this->assertNull($listing->item_name);
    }

    /** A group push sends the listing's own category and price rule; the group fills only what the listing leaves blank. */
    public function test_a_group_push_sends_the_listings_own_settings_over_the_groups(): void
    {
        $productId = $this->seedProduct(300.0);
        $group = $this->seedCatchAllGroup();
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);
        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100200], ['attributes' => []]);
        ShopeeListing::query()->create(['product_id' => $productId, 'shopee_category_id' => 100200, 'markup_fixed' => 70]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.push', $group->id), ['ids' => [$productId]]);

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The group push must reach add_item.');
        $this->assertSame(100200, (int) $add['body']['category_id'], "the listing's own category, not the group's");
        $this->assertSame(370.0, (float) $add['body']['original_price'], "the listing's own rule, not the group's 50%");
        $this->assertSame([['logistic_id' => 8003, 'enabled' => true]], $add['body']['logistic_info'], 'the group fills the couriers the listing leaves blank');
    }

    /** The listing page's group dropdown moves the product, and a value equal to the new group's follows it. */
    public function test_the_listing_saves_its_group_and_a_value_equal_to_the_group_follows_it(): void
    {
        $productId = $this->seedProduct(300.0);
        $first = $this->seedCatchAllGroup();
        $second = ShopeeProductGroup::create([
            'name' => 'drives', 'shopee_category_id' => 100200, 'logistic_ids' => [8003], 'markup_percent' => 10, 'markup_fixed' => 0,
        ]);
        DB::table('shopee_product_group_products')->insert(['shopee_product_group_id' => $first->id, 'product_id' => $productId]);

        $this->actingAs($this->manager())->post(route('ext.shopee.listings.update', $productId), [
            'product_group_listed' => 1, 'product_group_id' => $second->id,
            'shopee_category_id' => 100200, 'markup_percent' => 25,
        ])->assertSessionHasNoErrors();

        $this->assertSame([$second->id], DB::table('shopee_product_group_products')->where('product_id', $productId)
            ->pluck('shopee_product_group_id')->map(fn ($v) => (int) $v)->all(), 'moved, never in two groups');
        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertNull($listing->shopee_category_id, "equal to the group's category: it follows the group");
        $this->assertSame(25.0, (float) $listing->markup_percent, "different from the group's rule: the listing's own");

        $this->actingAs($this->manager())->post(route('ext.shopee.listings.update', $productId), [
            'product_group_listed' => 1, 'product_group_id' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('shopee_product_group_products')->where('product_id', $productId)->count(), 'No group takes it out');
    }

    /**
     * the owner, 2026-09-07: a store listing's own title and description are
     * saved per store and must go up from EVERY door. The group push built
     * its payload straight from the catalog, so a Shopee-only title sat
     * saved and unused while Shopee received the catalog's. One reader now
     * answers for every push path (App\Integrations\Listings\ListingContent).
     */
    public function test_a_group_push_sends_the_listings_own_words_not_the_catalogs(): void
    {
        $productId = $this->seedProduct(300.0);
        $group = $this->seedCatchAllGroup();
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);
        ShopeeListing::create([
            'product_id' => $productId,
            'item_name' => 'Shopee only title',
            'description' => 'Shopee only description.',
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.push', $group->id), ['ids' => [$productId]]);

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The group push must reach add_item.');
        $this->assertSame('Shopee only title', $add['body']['item_name']);
        $this->assertSame('Shopee only description.', $add['body']['description']);
    }

    /**
     * Description templates (the owner, 2026-09-07): the store's own words above
     * and below whichever description won, written once and pointed at from
     * the listing. They ride every push, from any door.
     */
    public function test_the_stores_description_templates_wrap_the_description_on_a_push(): void
    {
        $productId = $this->seedProduct(300.0);
        $group = $this->seedCatchAllGroup();
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);
        $storeId = (int) \Extensions\shopee\Models\ShopeeSetting::query()->orderBy('id')->value('id');
        $prefix = \App\Models\DescriptionTemplate::create([
            'integration' => 'shopee', 'store_id' => $storeId,
            'name' => 'Warranty', 'body' => 'One year warranty.',
        ]);
        $suffix = \App\Models\DescriptionTemplate::create([
            'integration' => 'shopee', 'store_id' => $storeId,
            'name' => 'Returns', 'body' => 'Returns within 7 days.',
        ]);
        ShopeeListing::create([
            'product_id' => $productId,
            'description' => 'The listing\'s own words.',
            'description_prefix_id' => $prefix->id,
            'description_suffix_id' => $suffix->id,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.push', $group->id), ['ids' => [$productId]]);

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add);
        $this->assertSame(
            "One year warranty.\n\nThe listing's own words.\n\nReturns within 7 days.",
            $add['body']['description'],
            'the store\'s templates wrap whichever description won'
        );

        // A template belonging to another store is never borrowed.
        $other = \App\Models\DescriptionTemplate::create([
            'integration' => 'shopee', 'store_id' => $storeId + 99,
            'name' => 'Someone else', 'body' => 'Not ours.',
        ]);
        ShopeeListing::query()->where('product_id', $productId)->update(['description_prefix_id' => $other->id]);
        $this->sentPayloads = [];
        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.push', $group->id), ['ids' => [$productId]]);
        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertStringNotContainsString('Not ours.', (string) ($add['body']['description'] ?? ''));
    }

    /** A listing's own Price is where the rule starts, on the first push, the review, the price sync and the cron. */
    public function test_a_listings_own_price_500_with_10_percent_added_pushes_550(): void
    {
        $productId = $this->seedProduct(300.0);
        ShopeeListing::create([
            'product_id' => $productId, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'markup_percent' => 10, 'price' => 500,
        ]);
        $user = $this->manager();

        $this->actingAs($user)->getJson(route('ext.shopee.products.push_review', $productId))
            ->assertOk()->assertJson(['price' => '550.00', 'core_price' => '300.00']);

        $this->actingAs($user)->post(route('ext.shopee.products.push_direct', $productId));
        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The push must reach add_item.');
        $this->assertSame(550.0, (float) $add['body']['original_price']);
        $this->assertSame(550.0, (float) ShopeeListing::query()->where('product_id', $productId)->first()->last_push_settings['price']);

        $this->sentPayloads = [];
        $this->actingAs($user)->post(route('ext.shopee.products.sync_price', $productId))->assertSessionMissing('error');
        $update = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_price');
        $this->assertNotNull($update);
        $this->assertSame(550.0, (float) $update['body']['price_list'][0]['original_price']);

        $this->sentPayloads = [];
        $this->artisan('shopee:push-price')->assertExitCode(0);
        $update = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_price');
        $this->assertNotNull($update);
        $this->assertSame(550.0, (float) $update['body']['price_list'][0]['original_price']);
    }

    public function test_a_group_push_starts_from_the_listings_own_price(): void
    {
        $productId = $this->seedProduct(300.0);
        $group = $this->seedCatchAllGroup();
        DB::table('shopee_product_group_products')->insert(['shopee_product_group_id' => $group->id, 'product_id' => $productId]);
        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100013], ['attributes' => []]);
        ShopeeListing::query()->create(['product_id' => $productId, 'price' => 500, 'markup_percent' => 10]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.push', $group->id), ['ids' => [$productId]]);

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The group push must reach add_item.');
        $this->assertSame(550.0, (float) $add['body']['original_price']);
    }

    public function test_a_blank_price_starts_from_the_catalog_price(): void
    {
        $productId = $this->seedProduct(300.0);
        ShopeeListing::create([
            'product_id' => $productId, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'markup_percent' => 10, 'price' => null,
        ]);

        $this->actingAs($this->manager())->post(route('ext.shopee.products.push_direct', $productId));

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add);
        $this->assertSame(330.0, (float) $add['body']['original_price']);
    }

    /** A catalog product without a price is still ready when the listing has its own. */
    public function test_a_listings_own_price_answers_a_catalog_without_one(): void
    {
        $productId = $this->seedProduct(0.0);
        ShopeeListing::create(['product_id' => $productId, 'shopee_category_id' => 100200, 'logistic_ids' => [8003]]);
        $gaps = fn () => array_column(app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)->forProducts([$productId])[$productId]['gaps'], 'code');

        $this->assertContains(\App\Integrations\Listings\ListingState::GAP_PRICE, $gaps());

        ShopeeListing::query()->where('product_id', $productId)->update(['price' => 500]);
        $this->assertNotContains(\App\Integrations\Listings\ListingState::GAP_PRICE, $gaps());
    }

    /** The listing's own weight and size go on every door; a blank field follows the catalog. */
    public function test_the_parcels_own_values_are_sent_and_blank_ones_follow_the_catalog(): void
    {
        $parcel = ['weight' => 2.5, 'package_length' => 30, 'package_width' => null, 'package_height' => null];
        $expect = fn (array $body) => [
            (float) $body['weight'],
            (int) $body['dimension']['package_length'], (int) $body['dimension']['package_width'], (int) $body['dimension']['package_height'],
        ];

        // The first push.
        $direct = $this->seedProduct(300.0);
        ShopeeListing::create(['product_id' => $direct, 'shopee_category_id' => 100200, 'logistic_ids' => [8003]] + $parcel);
        $this->actingAs($this->manager())->post(route('ext.shopee.products.push_direct', $direct));
        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add);
        $this->assertSame([2.5, 30, 10, 5], $expect($add['body']));

        // The group push.
        $this->sentPayloads = [];
        $grouped = $this->seedProduct(300.0);
        $group = $this->seedCatchAllGroup();
        DB::table('shopee_product_group_products')->insert(['shopee_product_group_id' => $group->id, 'product_id' => $grouped]);
        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100013], ['attributes' => []]);
        ShopeeListing::create(['product_id' => $grouped] + $parcel);
        $this->actingAs($this->manager())->post(route('ext.shopee.product-groups.push', $group->id), ['ids' => [$grouped]]);
        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The group push must reach add_item.');
        $this->assertSame([2.5, 30, 10, 5], $expect($add['body']));

        // The group update, onto the item the group push just made.
        $this->sentPayloads = [];
        $this->actingAs($this->manager())->post(route('ext.shopee.product-groups.updateProduct', $group->id), ['ids' => [$grouped]]);
        $update = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($update, 'The group update must reach update_item.');
        $this->assertSame([2.5, 30, 10, 5], $expect($update['body']));
    }
}
