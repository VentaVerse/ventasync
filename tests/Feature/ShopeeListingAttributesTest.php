<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeListingAttributesTest extends TestCase
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

                return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['item_id' => 990077]]];
            }
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                if ($path === '/api/v2/product/get_item_list') {
                    return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['item' => [], 'has_next_page' => false]]];
                }

                return ['ok' => false, 'status' => 500, 'body' => ['message' => 'not faked']];
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

        Http::fake([
            'shopee.test/*' => Http::response([
                'response' => ['image_info' => ['image_id' => 'img-001']],
            ], 200),
        ]);

        @mkdir(public_path('image/catalog'), 0775, true);
        file_put_contents(public_path('image/catalog/__attr-test.png'), 'png');

        ShopeeCategoryTemplate::query()->create([
            'category_id' => 106755,
            'region' => 'ph',
            'attributes' => [
                [
                    'attribute_id' => 1000, 'original_attribute_name' => 'Brand Origin',
                    'is_mandatory' => true, 'input_type' => 'DROP_DOWN',
                    'attribute_value_list' => [
                        ['value_id' => 71, 'original_value_name' => 'China'],
                        ['value_id' => 72, 'original_value_name' => 'Japan'],
                    ],
                ],
                [
                    'attribute_id' => 2000, 'original_attribute_name' => 'Warranty Type',
                    'is_mandatory' => true, 'input_type' => 'TEXT_FILED',
                    'attribute_value_list' => [],
                ],
            ],
            'fetched_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink(public_path('image/catalog/__attr-test.png'));
        parent::tearDown();
    }

    public function recordPayload(string $path, array $body): void
    {
        $this->sentPayloads[] = ['path' => $path, 'body' => $body];
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Attribute editors']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'ATTR-1', 'sku' => 'ATTR-SKU', 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/__attr-test.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Attribute test product', 'description' => 'Words.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_the_listing_page_renders_the_categorys_sheet_and_names_missing_required(): void
    {
        $productId = $this->seedProduct();
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('Category attributes');
        $r->assertSee('Brand Origin');
        $r->assertSee('Warranty Type');
        $r->assertDontSee('2 required attributes');
    }

    public function test_saving_answers_stores_them_and_readiness_clears(): void
    {
        $productId = $this->seedProduct();
        $user = $this->manager();
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
        ]);

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 106755,
            'logistic_ids' => [41003],
            'attributes' => ['1000' => 'China', '2000' => '2 years', '9999' => ''],
        ]);

        $listing = ShopeeListing::query()->where('product_id', $productId)->firstOrFail();
        $this->assertSame(['1000' => 'China', '2000' => '2 years'], $listing->attribute_values,
            'Answered attributes are kept, blank ones are not answers.');

        $this->actingAs($user)
            ->get(route('ext.shopee.listings.edit', $productId))
            ->assertDontSee('required attributes');
    }

    public function test_the_listing_editor_renders_the_sheet_for_the_saved_category(): void
    {
        $productId = $this->seedProduct();
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('Brand Origin');
        $r->assertSee('Warranty Type');
    }

    public function test_a_save_and_push_sends_the_answers_and_births_them_onto_the_listing(): void
    {
        $productId = $this->seedProduct();

        $this->actingAs($this->manager())->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 106755,
            'logistic_ids' => [41003],
            'item_name' => 'Attribute test product',
            'attributes' => ['1000' => 'Japan', '2000' => '1 year', '9999' => ''],
            'push_after' => 1,
        ]);

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'Save and push must reach add_item.');
        $list = collect($add['body']['attribute_list'] ?? []);
        $this->assertCount(2, $list, 'The answers given on the editor ride its own push.');
        $this->assertSame(72, (int) $list->firstWhere('attribute_id', 1000)['attribute_value_list'][0]['value_id']);

        $listing = ShopeeListing::query()->where('product_id', $productId)->firstOrFail();
        $this->assertSame(['1000' => 'Japan', '2000' => '1 year'], $listing->attribute_values,
            'The editor is where the listing is born; its answers are part of the birth.');
    }

    public function test_the_push_resolves_answers_into_shopees_attribute_list(): void
    {
        $productId = $this->seedProduct();
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
            'attribute_values' => ['1000' => 'China', '2000' => '2 years'],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push_direct', $productId));

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add);
        $list = collect($add['body']['attribute_list'] ?? []);
        $this->assertCount(2, $list, 'Both answers ride the push, keyed by attribute id (the group flow lost these by reading names).');

        $enum = $list->firstWhere('attribute_id', 1000);
        $this->assertSame(71, (int) $enum['attribute_value_list'][0]['value_id'],
            'An answer matching a published option goes by its value_id.');

        $text = $list->firstWhere('attribute_id', 2000);
        $this->assertSame(0, (int) $text['attribute_value_list'][0]['value_id']);
        $this->assertSame('2 years', $text['attribute_value_list'][0]['original_value_name'],
            'A free-text answer goes as a custom value.');
    }
}
