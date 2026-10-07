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

class ShopeeListingTest extends TestCase
{
    use RefreshDatabase;

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

        ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);

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

    private function seedCatchAllGroup(): ShopeeProductGroup
    {
        return ShopeeProductGroup::create([
            'name' => 'multi effects',
            'shopee_category_id' => 100013, 'logistic_ids' => [8003], 'markup_percent' => 50, 'markup_fixed' => 0,
        ]);
    }

    public function test_the_listing_editor_gives_birth_to_the_listing_record_and_pushes(): void
    {
        $productId = $this->seedProduct(300.0);
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
        $this->seedCatchAllGroup();

        $response = $this->actingAs($this->manager())
            ->from(route('ext.shopee.products.index'))
            ->post(route('ext.shopee.products.push_direct', $productId));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('a Shopee category', session('error'));

        $this->assertSame([], $this->sentPayloads);
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $productId)->count());
    }

    public function test_a_bulk_push_sends_what_is_ready_and_names_what_is_not(): void
    {
        $ready = $this->seedProduct(300.0);
        ShopeeListing::create([
            'product_id' => $ready, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'markup_percent' => 0, 'markup_fixed' => 70,
        ]);

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
        $this->seedCatchAllGroup();

        ShopeeListing::create([
            'product_id' => $productId, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'markup_percent' => 0, 'markup_fixed' => 70,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push_direct', $productId));

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The push must reach add_item.');
        $this->assertSame(370.0, (float) $add['body']['original_price']);
        $this->assertSame(100200, (int) $add['body']['category_id']);

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertSame(370.0, (float) $listing->last_push_settings['price']);
    }

    public function test_price_sync_prices_by_the_group_without_a_listing_rule_and_by_the_listing_with_one(): void
    {
        $productId = $this->seedProduct(300.0);
        $group = $this->seedCatchAllGroup();
        \Extensions\shopee\Models\ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $productId]);

        ShopeeProductLink::create([
            'product_id' => $productId, 'shopee_item_id' => 555, 'shopee_model_id' => null, 'sku' => 'LST-SKU-1',
        ]);

        $this->actingAs($this->manager())
            ->from(route('ext.shopee.products.index'))
            ->post(route('ext.shopee.products.sync_price', $productId))
            ->assertSessionMissing('error');
        $update = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_price');
        $this->assertNotNull($update);
        $this->assertSame(450.0, (float) $update['body']['price_list'][0]['original_price'], 'base 300 + the group\'s 50%');

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

        $this->assertNull($listing->shopee_category_id);
        $this->assertNull($listing->markup_fixed);
        $this->assertSame('Listing test product', $listing->item_name, 'A listing made by a group push starts as a copy of the catalog.');
    }

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

    public function test_a_listings_own_price_answers_a_catalog_without_one(): void
    {
        $productId = $this->seedProduct(0.0);
        ShopeeListing::create(['product_id' => $productId, 'shopee_category_id' => 100200, 'logistic_ids' => [8003]]);
        $gaps = fn () => array_column(app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)->forProducts([$productId])[$productId]['gaps'], 'code');

        $this->assertContains(\App\Integrations\Listings\ListingState::GAP_PRICE, $gaps());

        ShopeeListing::query()->where('product_id', $productId)->update(['price' => 500]);
        $this->assertNotContains(\App\Integrations\Listings\ListingState::GAP_PRICE, $gaps());
    }

    public function test_the_parcels_own_values_are_sent_and_blank_ones_follow_the_catalog(): void
    {
        $parcel = ['weight' => 2.5, 'package_length' => 30, 'package_width' => null, 'package_height' => null];
        $expect = fn (array $body) => [
            (float) $body['weight'],
            (int) $body['dimension']['package_length'], (int) $body['dimension']['package_width'], (int) $body['dimension']['package_height'],
        ];

        $direct = $this->seedProduct(300.0);
        ShopeeListing::create(['product_id' => $direct, 'shopee_category_id' => 100200, 'logistic_ids' => [8003]] + $parcel);
        $this->actingAs($this->manager())->post(route('ext.shopee.products.push_direct', $direct));
        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add);
        $this->assertSame([2.5, 30, 10, 5], $expect($add['body']));

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

        $this->sentPayloads = [];
        $this->actingAs($this->manager())->post(route('ext.shopee.product-groups.updateProduct', $group->id), ['ids' => [$grouped]]);
        $update = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($update, 'The group update must reach update_item.');
        $this->assertSame([2.5, 30, 10, 5], $expect($update['body']));
    }
}
