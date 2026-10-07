<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopeeListingPageTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_IMAGE_DIR = 'catalog/__shopee-listing-page-test';

    private array $sentPayloads = [];

    public array $getResponses = [];

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
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                $answer = $this->test->getResponses[$path] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($extraQuery);
                }

                return $answer
                    ?? ['ok' => false, 'status' => 500, 'body' => ['message' => 'unexpected GET ' . $path]];
            }
            public function signShop(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory(self::TEST_IMAGE_DIR);
        parent::tearDown();
    }

    public function recordPayload(string $path, array $body): void
    {
        $this->sentPayloads[] = ['path' => $path, 'body' => $body];
    }

    public function test_a_brand_list_is_warmed_one_page_at_a_time_and_then_served_from_the_cache(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        $this->getResponses['/api/v2/product/get_brand_list'] = function (array $q) {
            $offset = (int) ($q['offset'] ?? 0);

            return ['ok' => true, 'status' => 200, 'body' => ['response' => [
                'brand_list' => $offset === 0
                    ? [['brand_id' => 11, 'original_brand_name' => 'Boss'], ['brand_id' => 12, 'original_brand_name' => 'Ibanez']]
                    : [['brand_id' => 13, 'original_brand_name' => 'Walrus Audio']],
                'has_next_page' => $offset === 0,
            ]]];
        };
        $user = $this->manager();

        $first = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100013, 'warm' => 1, 'offset' => 0]))
            ->assertOk()->json();
        $this->assertFalse($first['complete']);
        $this->assertSame(2, $first['read']);
        $this->assertSame(100, $first['next_offset']);

        $second = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100013, 'warm' => 1, 'offset' => 100]))
            ->assertOk()->json();
        $this->assertTrue($second['complete']);
        $this->assertSame(3, $second['read']);

        $this->getResponses['/api/v2/product/get_brand_list'] = ['ok' => false, 'status' => 500, 'body' => ['message' => 'must not be called']];
        $again = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100013, 'warm' => 1, 'offset' => 0]))
            ->assertOk()->json();
        $this->assertTrue($again['complete']);

        $search = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100013, 'q' => 'wal', 'limit' => 25]))
            ->assertOk()->json();
        $this->assertTrue($search['cached']);
        $this->assertSame(['Walrus Audio'], array_column($search['brands'], 'name'));
    }

    private int $groupSeq = 0;

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Listing page group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $keys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        return $this->userWith(['manage_shopee/product', 'view_shopee/product']);
    }

    private function seedProduct(float $price = 300.0): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LSTP-1', 'sku' => 'LSTP-SKU-1', 'quantity' => 5, 'price' => $price,
            'status' => 1, 'image' => 'catalog/none.png', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);

        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Listing page product', 'description' => 'The catalog wording.',
            'meta_title' => 'Listing page product', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    private function baseInfoAnswer(array $overrides = []): array
    {
        $item = array_merge([
            'item_id' => 990077, 'item_status' => 'NORMAL',
            'item_name' => 'Listing page product', 'item_sku' => 'LSTP-SKU-1',
            'has_model' => false, 'has_promotion' => false, 'update_time' => 1756000000,
            'image' => ['image_url_list' => []],
            'price_info' => [['original_price' => 450.0, 'current_price' => 450.0]],
            'stock_info_v2' => ['summary_info' => ['total_available_stock' => 7]],
        ], $overrides);

        return ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => [$item]]]];
    }

    public function test_the_page_names_the_images_that_go_up_and_marks_what_stays_behind(): void
    {
        $productId = $this->seedProduct();
        $pfx = (string) config('catalog.prefix');
        for ($i = 1; $i <= 10; $i++) {
            DB::table($pfx . 'product_image')->insert([
                'product_id' => $productId, 'image' => "catalog/extra-{$i}.png", 'sort_order' => $i,
            ]);
        }

        $page = $this->actingAs($this->manager())->get(route('ext.shopee.listings.edit', $productId))->assertOk();
        $page->assertSee('Images that go up');

        $page->assertDontSee('images go up');
        $page->assertDontSee('held back');
        $page->assertSee('data-images-off', false);
    }

    public function test_the_listing_page_reports_nothing_about_health(): void
    {
        $pfx = (string) config('catalog.prefix');
        $productId = $this->seedProduct();
        DB::table($pfx . 'product')->where('product_id', $productId)->update(['sku' => '', 'model' => '']);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId))->assertOk()->getContent();

        foreach ([
            'Not on Shopee' => 'the live state word',
            'Last push' => 'the last push figure',
            'an SKU on the catalog product' => 'the readiness warning',
            'Open the catalog product' => "the warning's button",
        ] as $gone => $what) {
            $this->assertStringNotContainsString($gone, $html, "The page still reports {$what}.");
        }

        $this->assertStringContainsString('cl-strip--quiet', $html,
            'The bar keeps the verbs and loses the channel accent: a coloured bar reads as a status banner even when it says nothing.');

        $this->assertStringContainsString('data-combo-required="Pick a Shopee category from the list."', $html);
        $this->assertStringNotContainsString('formnovalidate', $html);
    }

    public function test_the_band_picks_the_group_and_the_fields_show_what_it_gives(): void
    {
        $productId = $this->seedProduct();
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Guitar pedals', 'shopee_category_id' => 100013,
            'logistic_ids' => [8003], 'markup_percent' => 50, 'markup_fixed' => 0,
        ]);
        \Extensions\shopee\Models\ShopeeProductGroupProduct::create([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);

        $page = $this->actingAs($this->manager())->get(route('ext.shopee.listings.edit', $productId))->assertOk();
        $page->assertSee('Product group');
        $page->assertSee('Guitar pedals');
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/<option value="' . $group->id . '"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/id="sl-markup-pct"[^>]*value="50(\.0+)?"|value="50(\.0+)?"[^>]*id="sl-markup-pct"/', $html,
            "the group's rule is the value in effect");
    }

    public function test_the_listing_keeps_its_own_arrangement_and_an_emptied_one_sends_the_catalogs(): void
    {
        $productId = $this->seedProduct();
        $pfx = (string) config('catalog.prefix');
        $paths = $this->seedRealImages($productId, 3);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'image_order' => json_encode($paths),
        ]);
        $listing = ShopeeListing::query()->where('product_id', $productId)->firstOrFail();
        $this->assertSame($paths, $listing->image_order, 'The listing keeps its own copy, even one equal to the catalog.');

        $reversed = array_reverse($paths);
        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'image_order' => json_encode($reversed),
        ]);
        $this->assertSame($reversed, $listing->fresh()->image_order);
        $this->assertSame(
            $reversed,
            app(\Extensions\shopee\Services\Shopee\ShopeeItemCreate::class)
                ->catalogImagePaths((object) ['product_id' => $productId], $listing->fresh()),
            'The push sends the arrangement, not the catalog order.'
        );

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);
        $this->assertSame($reversed, $listing->fresh()->image_order);

        $this->actingAs($user)->get(route('ext.shopee.listings.edit', $productId))
            ->assertSee('name="image_order" value="' . e(json_encode($reversed)), false);

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'image_order' => '[]',
        ]);
        $this->assertNull($listing->fresh()->image_order);
    }

    public function test_an_arrangement_takes_only_paths_the_library_actually_holds(): void
    {
        $productId = $this->seedProduct();
        $paths = $this->seedRealImages($productId, 2);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'image_order' => json_encode([
                '../../../.env',
                'catalog/../../.env',
                '/etc/passwd',
                'storage/logs/laravel.log',
                'catalog/does-not-exist-anywhere.png',
                $paths[1],
            ]),
        ]);

        $this->assertSame(
            [$paths[1]],
            ShopeeListing::query()->where('product_id', $productId)->value('image_order'),
            'Only a path the image library holds may reach a marketplace.'
        );
    }

    public function test_the_page_draws_the_arrangement_and_offers_the_library(): void
    {
        $productId = $this->seedProduct();
        $paths = $this->seedRealImages($productId, 11);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 100013,
            'logistic_ids' => [8003],
            'image_order' => array_reverse($paths),
        ]);

        $page = $this->actingAs($this->manager())->get(route('ext.shopee.listings.edit', $productId))->assertOk();
        $page->assertSee('data-listing-images', false);
        $page->assertSee('Add from library');
        $page->assertSee('data-path="' . end($paths) . '"', false);
        $page->assertDontSee('lc-images__item--held', false);
        $page->assertSee('data-images-off', false);
    }

    public function test_push_update_sends_the_listings_own_pictures_in_its_own_order(): void
    {
        $productId = $this->seedProduct();
        $paths = $this->seedRealImages($productId, 3);
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId, 'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'image_order' => array_reverse($paths),
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $seen = [];
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return \Illuminate\Support\Facades\Http::response([
                'response' => ['image_info' => ['image_id' => 'img-' . count($seen)]],
            ], 200);
        });

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId))
            ->assertRedirect();

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent, 'The update must reach update_item.');
        $this->assertSame(
            ['img-1', 'img-2', 'img-3'],
            $sent['body']['image']['image_id_list'] ?? [],
            'One id per picture, in the arrangement\'s order.'
        );
        $this->assertCount(3, $seen, 'Every picture is uploaded before the update names it.');
    }

    public function test_send_carries_the_price_and_the_stock_not_only_the_content(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990079, 'sku' => 'LSTP-SKU-1',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId, 'shopee_setting_id' => (int) ShopeeSetting::query()->value('id'),
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId))
            ->assertRedirect();

        $paths = collect($this->sentPayloads)->pluck('path')->all();
        $this->assertContains('/api/v2/product/update_item', $paths, 'the content still goes');
        $this->assertContains('/api/v2/product/update_price', $paths, 'the price goes with it');
        $this->assertContains('/api/v2/product/update_stock', $paths, 'the stock goes with it');
    }

    public function test_a_save_keeps_the_way_back_to_the_search(): void
    {
        $productId = $this->seedProduct();
        $back = '/channels/shopee/1/products?search=locks';

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.update', $productId), ['back' => $back])
            ->assertRedirect(route('ext.shopee.listings.edit', [$productId, 'back' => $back]));
    }

    public function test_save_and_push_updates_a_live_listing_and_returns_to_the_search(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990078, 'sku' => 'LSTP-SKU-1',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId, 'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();
        $back = '/channels/shopee/1/products?search=locks';

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.update', $productId), [
                'shopee_category_id' => 100013, 'logistic_ids' => [8003], 'push_after' => 1, 'back' => $back,
            ])
            ->assertRedirect($back)
            ->assertSessionMissing('error');

        $this->assertNotNull(collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item'), 'Save and push reaches update_item.');
    }

    public function test_a_listing_with_its_own_pictures_is_not_asked_for_a_catalog_image(): void
    {
        $productId = $this->seedProduct();
        $pfx = (string) config('catalog.prefix');
        $paths = $this->seedRealImages($productId, 2);
        DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => '']);

        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class);
        $before = $readiness->forProducts([$productId])[$productId]['missing'] ?? [];
        $this->assertContains('a product image on the catalog product', $before);

        ShopeeListing::query()->updateOrCreate(
            ['product_id' => $productId],
            ['shopee_category_id' => 100013, 'logistic_ids' => [8003], 'image_order' => $paths]
        );

        $after = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId]['missing'] ?? [];
        $this->assertNotContains('a product image on the catalog product', $after);
    }

    private function seedRealImages(int $productId, int $count): array
    {
        $pfx = (string) config('catalog.prefix');
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $paths = [];
        for ($i = 1; $i <= $count; $i++) {
            $path = self::TEST_IMAGE_DIR . "/pic-{$i}.png";
            $disk->put($path, 'not really a png');
            $paths[] = $path;
        }

        DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => $paths[0]]);
        foreach (array_slice($paths, 1) as $i => $path) {
            DB::table($pfx . 'product_image')->insert([
                'product_id' => $productId, 'image' => $path, 'sort_order' => $i + 1,
            ]);
        }

        return $paths;
    }

    public function test_an_unpushed_product_shows_readiness_instead_of_a_live_state(): void
    {
        $productId = $this->seedProduct();

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertDontSee('Stock on Shopee');
        $r->assertDontSee('Delist from Shopee');
    }

    public function test_saving_stores_settings_and_the_listings_own_words(): void
    {
        $productId = $this->seedProduct();
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013,
            'logistic_ids' => [8003],
            'markup_percent' => 10,
            'item_name' => 'A Shopee-only title',
            'description' => '',
            'weight' => '',
        ]);

        $listing = ShopeeListing::query()->where('product_id', $productId)->firstOrFail();
        $this->assertSame(100013, (int) $listing->shopee_category_id);
        $this->assertSame([8003], $listing->logistic_ids);
        $this->assertSame(10.0, (float) $listing->markup_percent);
        $this->assertSame('A Shopee-only title', $listing->item_name);
        $this->assertSame('The catalog wording.', $listing->description, 'A new listing starts as a copy of the catalog.');
        $this->assertNull($listing->weight);

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013,
            'logistic_ids' => [8003],
            'description' => '',
        ]);
        $this->assertNull($listing->fresh()->description);
        $this->assertSame('A Shopee-only title', $listing->fresh()->item_name, 'A save without the title leaves it alone.');

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013,
            'logistic_ids' => [8003],
            'item_name' => '',
        ])->assertSessionHasErrors('item_name');

        $this->assertSame('A Shopee-only title', $listing->fresh()->item_name);
    }

    public function test_a_linked_product_shows_shopees_live_answer(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('Delist from Shopee');
        $this->assertSame('NORMAL', ShopeeProductLink::query()->where('shopee_item_id', 990077)->value('live_status'));
    }

    public function test_when_shopee_does_not_answer_the_page_says_so_instead_of_substituting(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] =
            ['ok' => false, 'status' => 500, 'body' => ['message' => 'read timeout']];

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertDontSee('Delist from Shopee');
    }

    public function test_the_toggle_reaches_unlist_item_with_the_asked_direction(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.toggle', $productId), ['action' => 'unlist']);

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/unlist_item');
        $this->assertNotNull($sent, 'The toggle must reach unlist_item.');
        $this->assertSame(990077, (int) $sent['body']['item_list'][0]['item_id']);
        $this->assertTrue((bool) $sent['body']['item_list'][0]['unlist']);
        $r->assertSessionHas('status');
    }

    private function fakeItemList(array $idsByStatus): void
    {
        $this->getResponses['/api/v2/product/get_item_list'] = function (array $q) use ($idsByStatus) {
            $ids = $idsByStatus[$q['item_status'] ?? ''] ?? [];

            return ['ok' => true, 'status' => 200, 'body' => ['response' => [
                'total_count' => count($ids),
                'item' => ((int) ($q['page_size'] ?? 1)) === 1
                    ? array_slice(array_map(fn ($i) => ['item_id' => $i, 'item_status' => 'X'], $ids), 0, 1)
                    : array_map(fn ($i) => ['item_id' => $i, 'item_status' => 'X'], $ids),
                'has_next_page' => false,
            ]]];
        };
    }

    public function test_the_index_tabs_count_the_mirror(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'A', 'live_status' => 'NORMAL']);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        $r->assertSee('Under review');
        $r->assertSee('shopee_tab=live', false);
        $this->assertEmpty($this->sentPayloads);
    }

    public function test_the_page_reads_the_mirror_and_asks_shopee_for_nothing(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1',
            'live_status' => 'UNLIST', 'live_checked_at' => '2026-08-28 08:30:00',
        ]);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        $r->assertSee('lss__label">All<', false);
        $r->assertSee('class="x-segment-bar"', false);
        $r->assertSee('<span>All</span>', false);
        $r->assertDontSee('x-segment-group__k', false);
        $r->assertDontSee('<span>All products</span>', false);
        $r->assertSee('as of 08:30, Aug 28');
        $r->assertSee('Refresh from Shopee', false);
        $r->assertSee('Refresh from Shopee');
        $r->assertSee('shopee_tab=unlisted', false);
        $r->assertSee('Unpublished');
        $r->assertSee('Publish on Shopee');
        $this->assertEmpty($this->sentPayloads);
    }

    public function test_a_link_never_checked_is_filled_on_first_view_and_only_once(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1']);
        $calls = 0;
        $this->getResponses['/api/v2/product/get_item_base_info'] = function () use (&$calls) {
            $calls++;
            return $this->baseInfoAnswer(['item_status' => 'REVIEWING']);
        };

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));
        $r->assertOk();
        $r->assertSee('Under review');
        $r->assertDontSee('Not checked yet');
        $this->assertSame(1, $calls);
        $this->assertSame('REVIEWING', ShopeeProductLink::where('shopee_item_id', 990077)->value('live_status'));

        $this->actingAs($this->manager())->get(route('ext.shopee.products.index'))->assertOk();
        $this->assertSame(1, $calls, 'the second view reads the mirror and asks nothing');
    }

    public function test_a_link_shopee_will_not_answer_for_reads_not_checked_yet(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1']);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        $r->assertSee('Not checked yet');
        $r->assertSee('not refreshed yet');
    }

    public function test_refresh_walks_the_shop_and_writes_every_links_status(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1']);
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 424242, 'sku' => 'GONE']);
        $this->fakeItemList(['NORMAL' => [111, 222], 'UNLIST' => [333], 'BANNED' => [], 'REVIEWING' => [], 'SELLER_DELETE' => [], 'SHOPEE_DELETE' => []]);

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.products.refresh_status'));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 live', (string) session('status'));
        $this->assertSame('NORMAL', ShopeeProductLink::where('shopee_item_id', 111)->value('live_status'));
        $this->assertSame('MISSING', ShopeeProductLink::where('shopee_item_id', 424242)->value('live_status'), 'an item the shop no longer returns');
        $this->assertNotNull(ShopeeProductLink::where('shopee_item_id', 111)->value('live_checked_at'));
    }

    public function test_refresh_reads_the_attribute_sheets_its_listings_need(): void
    {
        $productId = $this->seedProduct();
        \Extensions\shopee\Models\ShopeeListing::query()->updateOrCreate(['product_id' => $productId], ['shopee_category_id' => 555001]);
        \Extensions\shopee\Models\ShopeeProductGroup::query()->create(['name' => 'Unread group', 'shopee_category_id' => 555002]);
        $this->fakeItemList(['NORMAL' => [], 'UNLIST' => [], 'BANNED' => [], 'REVIEWING' => [], 'SELLER_DELETE' => [], 'SHOPEE_DELETE' => []]);
        $this->getResponses['/api/v2/product/get_attribute_tree'] = fn (array $q) => (int) ($q['category_id'] ?? 0) === 555001
            ? ['ok' => true, 'status' => 200, 'body' => ['response' => ['attribute_list' => [['attribute_id' => 1, 'original_attribute_name' => 'Material', 'is_mandatory' => false]]]]]
            : ['ok' => true, 'status' => 200, 'body' => ['error' => 'error_param', 'message' => 'Invalid category.']];

        $this->actingAs($this->manager())->post(route('ext.shopee.products.refresh_status'))->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('Read 1 attribute sheet.', $status);
        $this->assertStringContainsString('1 attribute sheet could not be read.', $status);
        $this->assertTrue(\Extensions\shopee\Models\ShopeeCategoryTemplate::where('category_id', 555001)->exists());
        $this->assertFalse(\Extensions\shopee\Models\ShopeeCategoryTemplate::where('category_id', 555002)->exists(), 'an error answer is not filed as a sheet');
    }

    public function test_refresh_leaves_the_mirror_alone_when_shopee_does_not_answer(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1', 'live_status' => 'NORMAL',
        ]);

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.products.refresh_status'));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Shopee did not answer', (string) session('error'));
        $this->assertSame('NORMAL', ShopeeProductLink::where('shopee_item_id', 111)->value('live_status'));
    }

    public function test_a_shopee_tab_narrows_the_table_by_the_mirror(): void
    {
        $inTab = $this->seedProduct();
        $pfx = (string) config('catalog.prefix');
        ShopeeProductLink::query()->create(['product_id' => $inTab, 'shopee_item_id' => 111, 'sku' => 'TAB-IN', 'live_status' => 'NORMAL']);
        $outOfTab = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LSTP-2', 'sku' => 'TAB-OUT', 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $outOfTab, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Unlisted elsewhere product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        ShopeeProductLink::query()->create(['product_id' => $outOfTab, 'shopee_item_id' => 333, 'sku' => 'TAB-OUT', 'live_status' => 'UNLIST']);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index', ['shopee_tab' => 'live']));

        $r->assertOk();
        $r->assertSee('Listing page product');
        $r->assertDontSee('Unlisted elsewhere product');
        $this->assertEmpty($this->sentPayloads);
    }

    public function test_a_toggle_writes_through_to_the_mirror(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1', 'live_status' => 'NORMAL',
        ]);

        $this->actingAs($this->manager())->post(route('ext.shopee.listings.toggle', $productId), ['action' => 'unlist']);

        $this->assertSame('UNLIST', ShopeeProductLink::where('shopee_item_id', 990077)->value('live_status'));
    }

    public function test_bulk_toggle_unlists_the_selection_in_one_call_and_names_what_it_skipped(): void
    {
        $a = $this->seedProduct();
        $pfx = (string) config('catalog.prefix');
        $b = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LSTP-2', 'sku' => 'LSTP-SKU-2', 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        ShopeeProductLink::query()->create(['product_id' => $a, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1']);
        ShopeeProductLink::query()->create(['product_id' => $b, 'shopee_item_id' => 222, 'sku' => 'LSTP-SKU-2']);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.bulk_toggle'), ['action' => 'unlist', 'product_ids' => [$a, $b, 4040]]);

        $sent = collect($this->sentPayloads)->where('path', '/api/v2/product/unlist_item');
        $this->assertCount(1, $sent, 'one call for the whole selection');
        $items = collect($sent->first()['body']['item_list']);
        $this->assertEqualsCanonicalizing([111, 222], $items->pluck('item_id')->map('intval')->all());
        $this->assertTrue($items->every(fn ($i) => $i['unlist'] === true));

        $r->assertSessionHas('status');
        $flash = (string) session('status');
        $this->assertStringContainsString('Delisted 2 items from Shopee', $flash);
        $this->assertStringContainsString('1 skipped, not on Shopee', $flash);
    }

    public function test_bulk_toggle_with_nothing_on_shopee_refuses_plainly(): void
    {
        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.bulk_toggle'), ['action' => 'list', 'product_ids' => [4040]])
            ->assertSessionHas('error');
        $this->assertEmpty(collect($this->sentPayloads)->where('path', '/api/v2/product/unlist_item'));
    }

    public function test_push_update_sends_the_merged_listing_onto_the_live_item(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'item_name' => 'A Shopee-only title',
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId));

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent, 'The update must reach update_item.');
        $this->assertSame(990077, (int) $sent['body']['item_id']);
        $this->assertSame('A Shopee-only title', $sent['body']['item_name'], 'The override goes.');
        $this->assertSame('The catalog wording.', $sent['body']['description'], 'Null means the catalog goes.');
        $this->assertSame(100013, (int) $sent['body']['category_id']);
        $this->assertSame(8003, (int) $sent['body']['logistic_info'][0]['logistic_id']);
        $this->assertArrayNotHasKey('original_price', $sent['body'], 'Price never rides a content update.');
        $this->assertSame(0, (int) $sent['body']['brand']['brand_id'],
            'The brand block always rides an update - Shopee refuses one without it.');
        $this->assertSame('No Brand', $sent['body']['brand']['original_brand_name']);
        $this->assertArrayNotHasKey('seller_stock', $sent['body'], 'Stock never rides a content update.');
        $r->assertSessionHas('status');

        $listing = ShopeeListing::query()->where('product_id', $productId)->firstOrFail();
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertNotNull($listing->last_pushed_at);
    }

    public function test_push_update_wraps_the_description_in_the_stores_templates(): void
    {
        $productId = $this->seedProduct();
        $storeId = (int) ShopeeSetting::query()->value('id');
        $prefix = \App\Models\DescriptionTemplate::query()->create(['integration' => 'shopee', 'store_id' => $storeId, 'name' => 'Returns', 'body' => 'Returns within 7 days.']);
        $suffix = \App\Models\DescriptionTemplate::query()->create(['integration' => 'shopee', 'store_id' => $storeId, 'name' => 'Warranty', 'body' => 'One year warranty.']);
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId, 'shopee_setting_id' => $storeId,
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'description' => 'Shopee-only wording.',
            'description_prefix_id' => $prefix->id, 'description_suffix_id' => $suffix->id,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId))
            ->assertSessionHas('status');

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent, 'The update must reach update_item.');
        $this->assertSame("Returns within 7 days.\n\nShopee-only wording.\n\nOne year warranty.", $sent['body']['description'],
            'Prefix, the listing\'s own words, suffix: the same composition every other push sends.');
    }

    private function liveItemWithDescription(string $type, array $fields): void
    {
        $this->getResponses['/api/v2/product/get_item_base_info'] = ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => [[
            'item_id' => 990077, 'item_status' => 'NORMAL', 'item_name' => 'Qable IC50',
            'description' => $type === 'normal' ? 'Old words.' : '',
            'description_type' => $type,
            'description_info' => ['extended_description' => ['field_list' => $fields]],
        ]]]]];
    }

    private function listingWithWords(string $words): int
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1']);
        ShopeeListing::query()->create([
            'product_id' => $productId, 'shopee_setting_id' => (int) ShopeeSetting::query()->value('id'),
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'description' => $words,
        ]);

        return $productId;
    }

    public function test_push_update_writes_an_extended_description_in_its_own_shape(): void
    {
        $productId = $this->listingWithWords("Qable IC50\n\nAn instrument cable");
        $this->liveItemWithDescription('extended', [
            ['field_type' => 'text', 'text' => 'Old words.'],
            ['field_type' => 'image', 'image_info' => ['image_id' => 'sg-11134-art', 'image_url' => 'https://cf.shopee.sg/file/sg-11134-art']],
            ['field_type' => 'text', 'text' => 'More old words.'],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId))
            ->assertSessionHas('status');

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent, 'The update must reach update_item.');
        $this->assertArrayNotHasKey('description', $sent['body'], 'A plain description is what Shopee refuses on an extended item.');
        $this->assertSame('extended', $sent['body']['description_type']);
        $this->assertSame([
            ['field_type' => 'text', 'text' => "Qable IC50\n\nAn instrument cable"],
        ], $sent['body']['description_info']['extended_description']['field_list'],
            'The store\'s own image field is replaced by our description.');
    }

    private function catalogDescriptionWithTwoPictures(): int
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1']);

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $urls = [];
        foreach ([1, 2] as $i) {
            $path = self::TEST_IMAGE_DIR . "/desc-{$i}.png";
            $disk->put($path, 'not really a png');
            $urls[] = \App\Support\Catalog\ProductImages::url($path);
        }
        DB::table((string) config('catalog.prefix') . 'product_description')->where('product_id', $productId)->update([
            'description' => '<p>Intro words.</p><p><img src="' . e($urls[0]) . '" alt="first"></p>'
                . '<p>Middle words.</p><img src="' . e($urls[1]) . '"><p>Last words.</p>',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId, 'shopee_setting_id' => (int) ShopeeSetting::query()->value('id'),
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);

        return $productId;
    }

    private function fakeUploads(array $refused = []): \ArrayObject
    {
        $bodies = new \ArrayObject();
        \Illuminate\Support\Facades\Http::fake(function ($request) use ($bodies, $refused) {
            $bodies[] = $request->body();
            $n = count($bodies);

            return in_array($n, $refused, true)
                ? \Illuminate\Support\Facades\Http::response(['error' => 'error_image', 'message' => 'The picture is broken.'], 200)
                : \Illuminate\Support\Facades\Http::response(['response' => ['image_info' => ['image_id' => 'desc-' . $n]]], 200);
        });

        return $bodies;
    }

    public function test_an_extended_item_gets_our_words_and_pictures_in_our_order(): void
    {
        $productId = $this->catalogDescriptionWithTwoPictures();
        $this->liveItemWithDescription('extended', [
            ['field_type' => 'image', 'image_info' => ['image_id' => 'sg-11134-art']],
            ['field_type' => 'text', 'text' => 'Old words.'],
        ]);
        $uploads = $this->fakeUploads();

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId))
            ->assertSessionHas('status', fn (string $m) => ! str_contains($m, 'left out'));

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent, 'The update must reach update_item.');
        $this->assertSame([
            ['field_type' => 'text', 'text' => 'Intro words.'],
            ['field_type' => 'image', 'image_info' => ['image_id' => 'desc-1']],
            ['field_type' => 'text', 'text' => 'Middle words.'],
            ['field_type' => 'image', 'image_info' => ['image_id' => 'desc-2']],
            ['field_type' => 'text', 'text' => 'Last words.'],
        ], $sent['body']['description_info']['extended_description']['field_list']);
        $this->assertStringNotContainsString('sg-11134-art', json_encode($sent['body']), 'The store\'s old image field is not kept.');

        $this->assertCount(2, $uploads);
        foreach ($uploads as $body) {
            $this->assertMatchesRegularExpression('/name="scene"\r?\n(?:[^\r\n]+\r?\n)*\r?\ndesc\r?\n/', $body, 'A description picture goes up with scene desc.');
        }
    }

    public function test_a_description_picture_that_cannot_be_uploaded_is_left_out_and_said(): void
    {
        $productId = $this->catalogDescriptionWithTwoPictures();
        $this->liveItemWithDescription('extended', [['field_type' => 'text', 'text' => 'Old words.']]);
        $this->fakeUploads([2]);
        $states = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class);
        $states->recordOutcome($productId, 'An earlier refusal.');
        $this->assertContains($productId, $states->erroredProductIds());

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId))
            ->assertSessionMissing('error')
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, '1 description picture could not be uploaded and was left out.'));

        $this->assertNotContains($productId, app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->erroredProductIds(),
            'The update went, so its line clears: a picture left out is a warning, never the error line.');

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent, 'The rest of the description still goes.');
        $this->assertSame([
            ['field_type' => 'text', 'text' => 'Intro words.'],
            ['field_type' => 'image', 'image_info' => ['image_id' => 'desc-1']],
            ['field_type' => 'text', 'text' => "Middle words.\n\nLast words."],
        ], $sent['body']['description_info']['extended_description']['field_list']);
    }

    public function test_a_group_send_says_a_left_out_picture_as_a_warning(): void
    {
        $productId = $this->catalogDescriptionWithTwoPictures();
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Description pictures',
            'shopee_category_id' => 100013, 'logistic_ids' => [8003], 'markup_percent' => 0, 'markup_fixed' => 0,
        ]);
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);
        $this->liveItemWithDescription('extended', [['field_type' => 'text', 'text' => 'Old words.']]);
        $this->fakeUploads([2]);
        app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->recordOutcome($productId, 'An earlier refusal.');

        $this->actingAs($this->userWith(['manage_shopee/product', 'view_shopee/product', 'manage_shopee/product_group', 'view_shopee/product_group']))
            ->post(route('ext.shopee.product-groups.send', $group->id), ['ids' => [$productId]])
            ->assertSessionMissing('error')
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, "#{$productId}: 1 description picture could not be uploaded and was left out."));

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent, 'The group update still goes.');
        $this->assertSame([
            ['field_type' => 'text', 'text' => 'Intro words.'],
            ['field_type' => 'image', 'image_info' => ['image_id' => 'desc-1']],
            ['field_type' => 'text', 'text' => "Middle words.\n\nLast words."],
        ], $sent['body']['description_info']['extended_description']['field_list']);
        $this->assertNotContains($productId, app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->erroredProductIds(),
            'A picture left out is never the error line.');
    }

    public function test_a_normal_item_gets_plain_text_even_when_our_description_has_pictures(): void
    {
        $productId = $this->catalogDescriptionWithTwoPictures();
        $this->liveItemWithDescription('normal', []);
        $uploads = $this->fakeUploads();

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId))
            ->assertSessionHas('status');

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertSame("Intro words.\n\nMiddle words.\n\nLast words.", $sent['body']['description']);
        $this->assertArrayNotHasKey('description_type', $sent['body']);
        $this->assertArrayNotHasKey('description_info', $sent['body']);
        $this->assertCount(0, $uploads, 'A normal description cannot show pictures, so none go up.');
    }

    public function test_push_update_keeps_a_normal_description_plain(): void
    {
        $productId = $this->listingWithWords("Qable IC50\n\nAn instrument cable");
        $this->liveItemWithDescription('normal', []);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId))
            ->assertSessionHas('status');

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertSame("Qable IC50\n\nAn instrument cable", $sent['body']['description']);
        $this->assertArrayNotHasKey('description_type', $sent['body']);
        $this->assertArrayNotHasKey('description_info', $sent['body']);
    }

    public function test_the_words_of_an_extended_item_are_its_text_fields(): void
    {
        $this->assertSame("First part.\n\nSecond part.", \Extensions\shopee\Services\Shopee\ShopeeDescription::textOf([
            'description' => '',
            'description_info' => ['extended_description' => ['field_list' => [
                ['field_type' => 'text', 'text' => 'First part.'],
                ['field_type' => 'image', 'image_info' => ['image_id' => 'x']],
                ['field_type' => 'text', 'text' => 'Second part.'],
            ]]],
        ]));
        $this->assertSame('Plain words.', \Extensions\shopee\Services\Shopee\ShopeeDescription::textOf(['description' => 'Plain words.']));
    }

    public function test_push_update_refuses_a_product_that_is_not_on_shopee(): void
    {
        $productId = $this->seedProduct();

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId));

        $this->assertNull(collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item'));
        $r->assertSessionHas('error');
    }

    public function test_a_listing_with_a_brand_pushes_it_by_name(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        \Extensions\shopee\Models\ShopeeBrand::query()->create([
            'category_id' => 106755, 'brand_id' => 5511, 'name' => 'G7th',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
            'shopee_brand_id' => 5511,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId));

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertSame(5511, (int) $sent['body']['brand']['brand_id']);
        $this->assertSame('G7th', $sent['body']['brand']['original_brand_name']);
    }

    public function test_the_brand_search_answers_this_categorys_brands_only(): void
    {
        \Extensions\shopee\Models\ShopeeBrand::query()->create(['category_id' => 106755, 'brand_id' => 5511, 'name' => 'G7th']);
        \Extensions\shopee\Models\ShopeeBrand::query()->create(['category_id' => 106755, 'brand_id' => 5512, 'name' => 'Dunlop']);

        $r = $this->actingAs($this->userWith(['view_shopee/product']))
            ->get(route('ext.shopee.products.brandsForCategory', ['category_id' => 106755, 'q' => 'G7', 'limit' => 25]));

        $r->assertOk();
        $r->assertJson(['ok' => true]);
        $brands = $r->json('brands');
        $this->assertCount(1, $brands);
        $this->assertSame('G7th', $brands[0]['name']);
    }

    public function test_the_category_picker_endpoints_answer_to_the_products_key(): void
    {
        $this->actingAs($this->userWith(['view_shopee/product']))
            ->get(route('ext.shopee.products.searchCategories', ['q' => 'guitar']))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_a_check_stamps_the_product_not_the_page_it_ran_from(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Check stampers',
            'shopee_category_id' => 100013, 'logistic_ids' => [41003],
        ]);
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $this->actingAs($this->userWith([
            'manage_shopee/product', 'view_shopee/product',
            'manage_shopee/product_group', 'view_shopee/product_group',
        ]))->post(route('ext.shopee.product-groups.check', $group->id), ['ids' => [$productId]]);

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertNotNull($listing, 'The check must leave its stamp on the product record.');
        $this->assertNotNull($listing->last_checked_at);

        $pivot = DB::table('shopee_product_group_products')
            ->where('shopee_product_group_id', $group->id)
            ->where('product_id', $productId)->first();
        $this->assertNull($pivot->last_confirmed_at,
            'The per-group copy of the checked fact must no longer be written.');
    }

    public function test_the_workbench_check_needs_no_group(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.check'), ['product_ids' => [$productId]]);

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 still on Shopee', session('status'));
        $this->assertNotNull(ShopeeListing::query()->where('product_id', $productId)->value('last_checked_at'));
    }

    public function test_the_workbench_offers_the_check_and_match_ids_is_gone(): void
    {
        $this->seedProduct();
        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Link IDs', $html);
        $this->assertStringContainsString('Refresh from Shopee', $html);
        $this->assertStringNotContainsString('Repair links', $html);
        $this->assertStringNotContainsString('Match Shopee IDs', $html,
            'Match Shopee IDs is back; the check replaced it because it could only fill in blanks, never question an id a row already had');
    }

    public function test_the_row_menu_push_asks_before_publishing(): void
    {
        $productId = $this->seedProduct();
        \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Confirm scope',
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);
        $this->fakeItemList(['NORMAL' => [], 'UNLIST' => [], 'BANNED' => [], 'REVIEWING' => []]);
        $this->getResponses['/api/v2/product/get_item_base_info'] =
            ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => []]]];

        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100013], ['attributes' => []]);

        $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index'))
            ->assertSee(route('ext.shopee.products.push_direct', $productId), false)
            ->assertSee('data-push-review="' . route('ext.shopee.products.push_review', $productId) . '"', false);
    }

    public function test_an_unready_rows_push_leads_into_the_listing_editor(): void
    {
        $productId = $this->seedProduct();
        \Extensions\shopee\Models\ShopeeListing::create(['product_id' => $productId]);
        \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Unready scope',
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);
        $this->fakeItemList(['NORMAL' => [], 'UNLIST' => [], 'BANNED' => [], 'REVIEWING' => []]);
        $this->getResponses['/api/v2/product/get_item_base_info'] =
            ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => []]]];

        $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index'))
            ->assertDontSee(route('ext.shopee.products.push_direct', $productId), false)
            ->assertSee('Not ready yet - the listing page names what is still missing', false);
    }

    public function test_the_item_id_links_to_the_live_listing(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId))
            ->assertSee('https://shopee.ph/product/2002/990077', false);
    }

    public function test_a_view_only_operator_reads_the_page_without_verbs(): void
    {
        $productId = $this->seedProduct();

        $r = $this->actingAs($this->userWith(['view_shopee/product']))
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('You can read this.');
        $r->assertDontSee('Push to Shopee');
        $r->assertDontSee('Save listing');
    }

    public function test_a_page_that_repeats_the_brands_already_read_ends_the_warm(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        $calls = 0;
        $this->getResponses['/api/v2/product/get_brand_list'] = function (array $q) use (&$calls) {
            $calls++;

            return ['ok' => true, 'status' => 200, 'body' => ['response' => [
                'brand_list' => [['brand_id' => 21, 'original_brand_name' => 'Boss'], ['brand_id' => 22, 'original_brand_name' => 'Ibanez']],
                'has_next_page' => true,
                'next_offset' => (int) ($q['offset'] ?? 0) + 100,
            ]]];
        };
        $user = $this->manager();

        $first = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100014, 'warm' => 1, 'offset' => 0]))->assertOk()->json();
        $this->assertFalse($first['complete']);
        $this->assertSame(2, $first['read']);

        $second = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100014, 'warm' => 1, 'offset' => 100]))->assertOk()->json();
        $this->assertTrue($second['complete'], 'a page that grew the list by nothing is the end, whatever has_next_page says');
        $this->assertSame(2, $second['read']);

        $again = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100014, 'warm' => 1, 'offset' => 200]))->assertOk()->json();
        $this->assertTrue($again['complete']);
        $this->assertSame(2, $calls, 'read to the end, the warm never asks Shopee again');
    }

    public function test_both_brand_pickers_warm_through_the_one_endpoint_and_the_page_guards_a_stall(): void
    {
        $this->assertStringContainsString('data-combo-warm', (string) file_get_contents(base_path('extensions/shopee/views/listings/edit.blade.php')));
        $this->assertStringContainsString('data-combo-warm', (string) file_get_contents(base_path('extensions/shopee/views/product-groups/form.blade.php')));
        $this->assertStringContainsString('app(ShopeeProductGroupController::class)->brandsForCategory(', (string) file_get_contents(base_path('extensions/shopee/Controllers/ShopeeProductController.php')), 'the listing route delegates to the one warm');
        $js = (string) file_get_contents(base_path('resources/js/pages/group-form.js'));
        $this->assertStringContainsString('stalls >= 3', $js);
        $this->assertStringContainsString('kept sending the same page', $js);
    }
    public function test_a_row_shows_the_stores_own_title_and_the_search_finds_it(): void
    {
        $pfx = (string) config('catalog.prefix');
        $titled = $this->seedProduct();
        $plain = $this->seedProduct();
        DB::table($pfx . 'product_description')->where('product_id', $plain)->update(['name' => 'Plain catalog pedal']);
        ShopeeListing::query()->create(['product_id' => $titled, 'item_name' => 'Qable IC50 Pro']);
        ShopeeListing::query()->create(['product_id' => $plain]);
        $other = ShopeeSetting::query()->create([
            'partner_id' => 1002, 'partner_key' => 'k2', 'shop_id' => 3003,
            'access_token' => 't2', 'refresh_token' => 'r2', 'mode' => 'production',
        ]);
        ShopeeListing::query()->create(['shopee_setting_id' => $other->id, 'product_id' => $plain, 'item_name' => 'Qable elsewhere']);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create(['name' => 'Titled pedals', 'shopee_category_id' => 100013, 'logistic_ids' => [8003]]);
        foreach ([$titled, $plain] as $pid) {
            \Extensions\shopee\Models\ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'pending']);
        }
        $user = $this->userWith(['manage_shopee/product', 'view_shopee/product', 'view_shopee/product_group']);

        foreach ([route('ext.shopee.products.index'), route('ext.shopee.product-groups.products', $group->id)] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Qable IC50 Pro')
                ->assertSee('Plain catalog pedal')
                ->assertDontSee('Listing page product')
                ->assertDontSee('Qable elsewhere');
        }

        foreach ([route('ext.shopee.products.index', ['q' => 'Qable']), route('ext.shopee.product-groups.products', ['id' => $group->id, 'q' => 'Qable'])] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Qable IC50 Pro')
                ->assertDontSee('Plain catalog pedal');
        }
    }

    private function giveVariations(int $productId): void
    {
        DB::table('product_option_combinations')->insert([
            'product_id' => $productId, 'sku' => 'LSTP-VAR-1', 'quantity' => 3,
            'absolute_price' => 300, 'image' => null, 'status' => 1,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_page_draws_the_shared_price_and_parcel_cards(): void
    {
        $productId = $this->seedProduct(300.0);
        ShopeeListing::query()->create(['product_id' => $productId, 'price' => 500, 'markup_percent' => 10, 'weight' => 2.5]);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.listings.edit', $productId))->assertOk();

        $r->assertSee('data-own-price', false);
        $r->assertSee('Price on Shopee after push');
        $r->assertSee('550.00');
        $r->assertSee('Weight in kg');
        $r->assertDontSee('The catalog now says');
        $this->assertSame(1, substr_count($r->getContent(), '>Price rule<'), 'one price rule card');
    }

    public function test_a_variation_product_shows_no_price_field_and_ignores_a_stored_one(): void
    {
        $productId = $this->seedProduct(300.0);
        $this->giveVariations($productId);
        ShopeeListing::query()->create(['product_id' => $productId, 'price' => 999, 'markup_percent' => 10]);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.listings.edit', $productId))->assertOk();
        $r->assertDontSee('data-own-price', false);
        $r->assertSee('330.00');
        $r->assertDontSee('1,098.90');

        $this->actingAs($this->manager())->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013, 'logistic_ids' => [8003], 'price' => 700,
        ])->assertSessionHasNoErrors();
        $this->assertNull(ShopeeListing::query()->where('product_id', $productId)->value('price'));
    }

    public function test_saving_keeps_the_listings_own_price_and_parcel_and_blank_follows_the_catalog(): void
    {
        $productId = $this->seedProduct(300.0);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'price' => '500', 'weight' => '2.5', 'package_length' => '30', 'package_width' => '', 'package_height' => '',
        ])->assertSessionHasNoErrors();
        $listing = ShopeeListing::query()->where('product_id', $productId)->firstOrFail();
        $this->assertSame(500.0, (float) $listing->price);
        $this->assertSame(2.5, (float) $listing->weight);
        $this->assertSame(30, (int) $listing->package_length);
        $this->assertNull($listing->package_width);

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013, 'logistic_ids' => [8003], 'price' => '',
        ]);
        $this->assertNull($listing->fresh()->price, 'Blank means the catalog price.');

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013, 'logistic_ids' => [8003], 'price' => '-1',
        ])->assertSessionHasErrors('price');
    }

    public function test_push_update_sends_the_listings_own_parcel_and_the_catalogs_for_blank_fields(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1']);
        ShopeeListing::query()->create([
            'product_id' => $productId, 'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'weight' => 2.5, 'package_length' => 30,
        ]);

        $this->actingAs($this->manager())->post(route('ext.shopee.listings.push_update', $productId));

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent);
        $this->assertSame(2.5, (float) $sent['body']['weight']);
        $this->assertSame(['package_length' => 30, 'package_width' => 10, 'package_height' => 5], array_map('intval', $sent['body']['dimension']));
    }
}
