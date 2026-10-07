<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeVariationPushTest extends TestCase
{
    use RefreshDatabase;

    private array $sentPayloads = [];

    public ?array $initRefusal = null;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 106755], ['attributes' => ['attribute_list' => []], 'fetched_at' => now()]);

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

                if ($path === '/api/v2/product/init_tier_variation') {
                    if ($this->test->initRefusal !== null) {
                        return $this->test->initRefusal;
                    }
                    $models = array_map(fn ($m, $i) => [
                        'model_id' => 700001 + $i,
                        'model_sku' => $m['model_sku'],
                        'tier_index' => $m['tier_index'],
                    ], $body['model'] ?? [], array_keys($body['model'] ?? []));

                    return ['ok' => true, 'status' => 200, 'body' => [
                        'error' => '', 'message' => '',
                        'response' => ['item_id' => $body['item_id'], 'model' => $models],
                    ]];
                }

                return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['item_id' => 990077]]];
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
                'response' => ['image_info' => ['image_id' => 'img-tier-001']],
            ], 200),
        ]);

        @mkdir(public_path('image/catalog'), 0775, true);
        file_put_contents(public_path('image/catalog/__vp-parent.png'), 'png');
        file_put_contents(public_path('image/catalog/__vp-red.png'), 'png');
    }

    protected function tearDown(): void
    {
        @unlink(public_path('image/catalog/__vp-parent.png'));
        @unlink(public_path('image/catalog/__vp-red.png'));
        parent::tearDown();
    }

    public function recordPayload(string $path, array $body): void
    {
        $this->sentPayloads[] = ['path' => $path, 'body' => $body];
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Variation pushers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedVariationProduct(): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'VP-1', 'sku' => 'VP-SKU', 'quantity' => 32, 'price' => 21990,
            'status' => 1, 'image' => 'catalog/__vp-parent.png', 'weight' => 3, 'length' => 20, 'width' => 20, 'height' => 20,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Variation push product', 'description' => 'Two colours.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        $optionId = (int) DB::table($pfx . 'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
        DB::table($pfx . 'option_description')->insert([
            'option_id' => $optionId, 'language_id' => $langId, 'name' => 'Colour',
        ]);
        $productOptionId = (int) DB::table($pfx . 'product_option')->insertGetId([
            'product_id' => $productId, 'option_id' => $optionId, 'value' => '', 'required' => 0,
        ]);

        foreach ([['Red', 'VP-RED', 14, 'catalog/__vp-red.png'], ['Black', 'VP-BLACK', 18, null]] as $i => [$name, $sku, $qty, $image]) {
            $valueId = (int) DB::table($pfx . 'option_value')->insertGetId([
                'option_id' => $optionId, 'image' => '', 'sort_order' => $i,
            ]);
            DB::table($pfx . 'option_value_description')->insert([
                'option_value_id' => $valueId, 'language_id' => $langId,
                'option_id' => $optionId, 'name' => $name,
            ]);
            $povId = (int) DB::table($pfx . 'product_option_value')->insertGetId([
                'product_option_id' => $productOptionId, 'product_id' => $productId,
                'option_id' => $optionId, 'option_value_id' => $valueId,
                'sku' => $sku, 'quantity' => $qty, 'subtract' => 1,
                'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
                'weight' => 0, 'weight_prefix' => '+',
                'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0,
                'absolute_cost' => 0, 'cost_prefix' => '+', 'absolute_price' => 21990,
            ]);
            $comboId = (int) DB::table('product_option_combinations')->insertGetId([
                'product_id' => $productId, 'sku' => $sku, 'quantity' => $qty,
                'absolute_price' => 21990, 'image' => $image, 'status' => 1,
                'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_option_combination_values')->insert([
                'combination_id' => $comboId, 'product_option_value_id' => $povId,
            ]);
        }

        return $productId;
    }

    public function test_a_one_click_push_gives_the_item_its_tiers_and_models(): void
    {
        $productId = $this->seedVariationProduct();
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
            'markup_percent' => 0, 'markup_fixed' => 10,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push_direct', $productId));

        $init = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/init_tier_variation');
        $this->assertNotNull($init, 'The push must reach init_tier_variation.');
        $this->assertSame(990077, (int) $init['body']['item_id']);

        $tier = $init['body']['tier_variation'][0];
        $this->assertSame('Colour', $tier['name']);
        $this->assertSame(['Red', 'Black'], array_column($tier['option_list'], 'option'));
        $this->assertSame('img-tier-001', $tier['option_list'][0]['image']['image_id'] ?? null,
            'Red carries its own uploaded picture.');
        $this->assertArrayNotHasKey('image', $tier['option_list'][1],
            'Black has no picture and goes without one.');

        $models = $init['body']['model'];
        $this->assertCount(2, $models);
        $this->assertSame([0], $models[0]['tier_index']);
        $this->assertSame('VP-RED', $models[0]['model_sku']);
        $this->assertSame(22000.0, (float) $models[0]['original_price'], 'The listing rule prices each model.');
        $this->assertSame(14, (int) $models[0]['seller_stock'][0]['stock']);
        $this->assertSame([1], $models[1]['tier_index']);

        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        $this->assertCount(2, $links);
        $this->assertTrue($links->every(fn ($l) => $l->shopee_model_id !== null));
        $this->assertEqualsCanonicalizing([700001, 700002], $links->pluck('shopee_model_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, ShopeeItemCache::query()->where('shopee_item_id', 990077)->count());
    }

    public function test_a_new_item_is_created_as_before_with_no_rename(): void
    {
        $productId = $this->seedVariationProduct();
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
            'markup_percent' => 0, 'markup_fixed' => 10,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push_direct', $productId));

        $paths = array_column($this->sentPayloads, 'path');
        $this->assertContains('/api/v2/product/add_item', $paths);
        $this->assertContains('/api/v2/product/init_tier_variation', $paths);
        $this->assertNotContains('/api/v2/product/update_tier_variation', $paths);
        $this->assertNotContains('/api/v2/product/update_item', $paths);

        $init = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/init_tier_variation');
        $this->assertSame('Colour', $init['body']['tier_variation'][0]['name']);
        $this->assertSame(['Red', 'Black'], array_column($init['body']['tier_variation'][0]['option_list'], 'option'));
    }

    public function test_a_plain_product_triggers_no_tier_call(): void
    {
        $pfx = (string) config('catalog.prefix');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'VP-2', 'sku' => 'VP2-SKU', 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => 'catalog/__vp-parent.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Plain product', 'description' => 'No colours.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push_direct', $productId));

        $this->assertNotNull(collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item'));
        $this->assertNull(collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/init_tier_variation'));
        $this->assertSame(1, ShopeeProductLink::query()->where('product_id', $productId)->whereNull('shopee_model_id')->count());
    }

    public function test_a_knowable_price_ratio_stops_the_push_at_the_door(): void
    {
        $productId = $this->seedVariationProduct();
        DB::table('product_option_combinations')
            ->where('product_id', $productId)->where('sku', 'VP-RED')
            ->update(['absolute_price' => 21990 * 6]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
            'markup_percent' => 0, 'markup_fixed' => 10,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push_direct', $productId))
            ->assertRedirect();

        $this->assertStringContainsString('more than 5 times', (string) session('error'));
        $this->assertNull(collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item'),
            'the refusal is known in advance, so the item is never created');
    }

    public function test_a_refused_variation_push_takes_the_half_created_item_down(): void
    {
        $productId = $this->seedVariationProduct();
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
            'markup_percent' => 0, 'markup_fixed' => 10,
        ]);
        $this->initRefusal = ['ok' => true, 'status' => 200, 'body' => [
            'error' => 'error_param',
            'message' => 'The price ratio of the most expensive to cheapest variation exceeds 5 (including promotion price). Please adjust the prices.',
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push_direct', $productId))
            ->assertRedirect();

        $flash = (string) session('error');
        $this->assertStringContainsString('variations were refused', $flash);
        $this->assertStringContainsString('removed from Shopee again', $flash);

        $delete = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/delete_item');
        $this->assertNotNull($delete, 'the half-created item is deleted at Shopee');
        $this->assertSame(990077, (int) $delete['body']['item_id']);
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $productId)->count(),
            'no link row survives a rolled-back create');
    }
}
