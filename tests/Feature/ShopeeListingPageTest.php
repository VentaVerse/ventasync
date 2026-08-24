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

/**
 * The individual listing page (quest 1, 2026-08-24): one product on Shopee,
 * shown the way the seller center shows it. The live half is fetched from
 * the marketplace on every load and NEVER substituted with stale data; the
 * record half edits the listing's own settings and content, where an empty
 * content field means "follows the catalogue".
 */
class ShopeeListingPageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{path: string, body: array}> */
    private array $sentPayloads = [];

    /** What the fake marketplace answers per GET path. */
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

    public function recordPayload(string $path, array $body): void
    {
        $this->sentPayloads[] = ['path' => $path, 'body' => $body];
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
            'name' => 'Listing page product', 'description' => 'The catalogue wording.',
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

    public function test_an_unpushed_product_shows_readiness_instead_of_a_live_state(): void
    {
        $productId = $this->seedProduct();

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('Not on Shopee yet');
        $r->assertSee('a Shopee category');
        $r->assertDontSee('On Shopee right now');
    }

    public function test_saving_stores_settings_and_blank_content_follows_the_catalogue(): void
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
        $this->assertNull($listing->description, 'Blank means: follows the catalogue.');
        $this->assertNull($listing->weight);

        // Emptying a filled field ends the divergence.
        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013,
            'logistic_ids' => [8003],
            'item_name' => '',
        ]);

        $this->assertNull($listing->fresh()->item_name, 'Clearing the field must return it to the catalogue.');
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
        $r->assertSee('On Shopee right now');
        $r->assertSee('Live');
        $r->assertSee('Unlist from Shopee');
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
        $r->assertSee('Shopee did not answer');
        $r->assertDontSee('Unlist from Shopee');
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

    /** get_item_list answers: counts per status, and one page of ids. */
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

    public function test_the_index_tabs_speak_shopees_live_counts(): void
    {
        $this->seedProduct();
        $this->fakeItemList(['NORMAL' => [111, 222], 'UNLIST' => [333], 'BANNED' => [], 'REVIEWING' => []]);
        $this->getResponses['/api/v2/product/get_item_base_info'] =
            ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => []]]];

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        $r->assertSee('Under review');
        $r->assertSee('shopee_tab=live', false);
    }

    public function test_a_live_tab_narrows_the_table_to_items_shopee_holds_in_that_status(): void
    {
        $inTab = $this->seedProduct();
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product')->where('product_id', $inTab)->update(['sku' => 'TAB-IN']);
        ShopeeProductLink::query()->create(['product_id' => $inTab, 'shopee_item_id' => 111, 'sku' => 'TAB-IN']);

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
        ShopeeProductLink::query()->create(['product_id' => $outOfTab, 'shopee_item_id' => 333, 'sku' => 'TAB-OUT']);

        $this->fakeItemList(['NORMAL' => [111], 'UNLIST' => [333], 'BANNED' => [], 'REVIEWING' => []]);
        $this->getResponses['/api/v2/product/get_item_base_info'] =
            ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => [
                ['item_id' => 111, 'item_status' => 'NORMAL'],
            ]]]];

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index', ['shopee_tab' => 'live']));

        $r->assertOk();
        $r->assertSee('Listing page product');
        $r->assertDontSee('Unlisted elsewhere product');
        // The page-load fetch also answers the row's chip and its toggle.
        $r->assertSee('Unlist from Shopee');
    }

    public function test_when_shopee_does_not_answer_the_tabs_step_aside_and_the_table_survives(): void
    {
        $productId = $this->seedProduct();
        // Linked, so the catalogue view lists it even with the API down.
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1',
        ]);
        // No get_item_list response registered: the fake answers ok=false.

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        $r->assertSee('Shopee did not answer');
        $r->assertSee('Listing page product');
        $r->assertDontSee('shopee_tab=live', false);
    }

    public function test_a_link_shopee_does_not_recognise_reads_not_found_instead_of_listed(): void
    {
        $productId = $this->seedProduct();
        // A link row whose item the shop has never heard of - an item
        // deleted in the seller center, or a row copied in from another
        // environment's database refresh.
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 424242, 'sku' => 'LSTP-SKU-1',
        ]);
        $this->fakeItemList(['NORMAL' => [], 'UNLIST' => [], 'BANNED' => [], 'REVIEWING' => []]);
        // base_info succeeds but answers with no items at all.
        $this->getResponses['/api/v2/product/get_item_base_info'] =
            ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => []]]];

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        $r->assertSee('Not found on Shopee');
        $r->assertDontSee('Unlist from Shopee');
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
            // description left null: the catalogue's wording goes.
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId));

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent, 'The update must reach update_item.');
        $this->assertSame(990077, (int) $sent['body']['item_id']);
        $this->assertSame('A Shopee-only title', $sent['body']['item_name'], 'The override goes.');
        $this->assertSame('The catalogue wording.', $sent['body']['description'], 'Null means the catalogue goes.');
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
        // The push form and listing page's combobox search under THEIR OWN
        // permission key: an operator with shopee/product but no
        // shopee/product_group must not need the group key to pick a
        // category.
        $this->actingAs($this->userWith(['view_shopee/product']))
            ->get(route('ext.shopee.products.searchCategories', ['q' => 'guitar']))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_the_page_is_a_manage_surface_even_as_a_get(): void
    {
        $productId = $this->seedProduct();

        $this->actingAs($this->userWith(['view_shopee/product']))
            ->get(route('ext.shopee.listings.edit', $productId))
            ->assertForbidden();
    }
}
