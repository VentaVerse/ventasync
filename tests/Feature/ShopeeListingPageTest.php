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
                return $this->test->getResponses[$path]
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

    public function test_the_page_is_a_manage_surface_even_as_a_get(): void
    {
        $productId = $this->seedProduct();

        $this->actingAs($this->userWith(['view_shopee/product']))
            ->get(route('ext.shopee.listings.edit', $productId))
            ->assertForbidden();
    }
}
