<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokCategoryTemplate;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TiktokAttributesTest extends TestCase
{
    use RefreshDatabase;

    private array $sentCalls = [];
    public array $callResponses = [];
    private string $imageFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3),
        ]);
        TikTokCategory::create(['id' => '900009', 'name' => 'Guitar Accessories', 'parent_id' => null, 'is_leaf' => true]);

        $dir = public_path('storage/catalog');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->imageFile = $dir . '/__tt-attr-test.png';
        $im = imagecreatetruecolor(4, 4);
        imagepng($im, $this->imageFile);
        imagedestroy($im);

        $this->callResponses['GET /product/202309/categories/900009/attributes'] = [
            'ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['attributes' => [
                ['id' => '100001', 'name' => 'Brand Origin', 'type' => 'PRODUCT_PROPERTY', 'is_requried' => true, 'is_customizable' => false,
                 'values' => [['id' => 'v-ph', 'name' => 'Philippines'], ['id' => 'v-jp', 'name' => 'Japan']]],
                ['id' => '100002', 'name' => 'Material', 'type' => 'PRODUCT_PROPERTY', 'is_requried' => false, 'is_customizable' => true, 'values' => []],
                ['id' => '100003', 'name' => 'Color', 'type' => 'SALES_PROPERTY', 'is_requried' => false, 'values' => []],
            ]]],
        ];

        $test = $this;
        $fake = new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function get(string $appKey, string $appSecret, string $accessToken, string $path, array $extraParams = [], ?string $shopCipher = null): array
            { return $this->test->answer('GET', $path, $extraParams); }
            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            { return $this->test->answer('POST', $path, $body); }
            public function put(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            { return $this->test->answer('PUT', $path, $body); }
            public function uploadImage(string $appKey, string $appSecret, string $accessToken, string $imageData, ?string $shopCipher = null): array
            { return $this->test->answer('POST', '/product/202309/images/upload', ['bytes' => strlen($imageData)]); }
        };
        $this->app->instance(TikTokClient::class, $fake);
    }

    protected function tearDown(): void
    {
        if ($this->imageFile !== '' && file_exists($this->imageFile)) {
            unlink($this->imageFile);
        }
        parent::tearDown();
    }

    public function answer(string $method, string $path, array $body): array
    {
        $this->sentCalls[] = ['method' => $method, 'path' => $path, 'body' => $body];
        $answer = $this->callResponses[$method . ' ' . $path] ?? null;
        if ($answer instanceof \Closure) {
            return $answer($body);
        }

        return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['uri' => 'img-' . count($this->sentCalls)]]];
    }

    private function callsFor(string $method, string $path): array
    {
        return array_values(array_filter($this->sentCalls, fn ($c) => $c['method'] === $method && $c['path'] === $path));
    }

    private int $groupSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok attribute answerers ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/product_group', 'view_tiktok/product_group'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'TTA-1', 'sku' => 'TTA-SKU', 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/__tt-attr-test.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Attribute product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_opening_a_group_reads_and_caches_the_sheet_and_lists_only_product_properties(): void
    {
        $group = TikTokProductGroup::create(['name' => 'Sheet group', 'tiktok_category_id' => '900009']);

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.product-groups.edit', $group->id));

        $r->assertOk();
        $r->assertSee('Brand Origin');
        $r->assertSee('Material');
        $r->assertDontSee('TikTok attribute 100003');
        $this->assertCount(1, $this->callsFor('GET', '/product/202309/categories/900009/attributes'));
        $this->assertNotNull(TikTokCategoryTemplate::where('category_id', '900009')->first());

        $this->actingAs($this->manager())->get(route('ext.tiktok.product-groups.edit', $group->id))->assertOk();
        $this->assertCount(1, $this->callsFor('GET', '/product/202309/categories/900009/attributes'));
    }

    public function test_a_groups_answers_ride_the_push_as_product_attributes_and_the_truth_lands_on_the_listing(): void
    {
        $productId = $this->seedProduct();
        $group = TikTokProductGroup::create(['name' => 'Answer group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending']);

        $this->actingAs($this->manager())->put(route('ext.tiktok.product-groups.update', $group->id), [
            'name' => 'Answer group', 'tiktok_category_id' => '900009',
            'product_ids' => [$productId],
            'attributes' => ['100001' => 'v-ph', '100002' => 'Maple'],
        ])->assertSessionHas('status');
        $this->assertSame(['100001' => 'v-ph', '100002' => 'Maple'], $group->attributes()->pluck('value', 'attribute_key')->all());

        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-777', 'skus' => [['id' => 'sku-1', 'seller_sku' => 'TTA-SKU']]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.product-groups.push', $group->id), ['ids' => [$productId]]);

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $this->assertSame([
            ['id' => '100001', 'values' => [['id' => 'v-ph', 'name' => 'Philippines']]],
            ['id' => '100002', 'values' => [['name' => 'Maple']]],
        ], $creates[0]['body']['product_attributes']);
        $this->assertSame('900009', $creates[0]['body']['category_id']);
        $this->assertStringNotContainsString("\u{2014}", $creates[0]['body']['title'], 'No em dash padding in a title.');

        $listing = TikTokListing::where('product_id', $productId)->first();
        $this->assertSame('tt-777', $listing->tiktok_product_id);
        $this->assertSame('sku-1', $listing->tiktok_sku_id);
        $this->assertSame('group:Answer group', $listing->last_push_source);
        $this->assertSame('tt-777', TikTokProductGroupProduct::where('product_id', $productId)->value('tiktok_product_id'));
    }

    public function test_a_group_push_sends_the_listings_own_words_not_the_catalogs(): void
    {
        $productId = $this->seedProduct();
        $group = TikTokProductGroup::create(['name' => 'Words group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending']);
        $this->actingAs($this->manager())->put(route('ext.tiktok.product-groups.update', $group->id), [
            'name' => 'Words group', 'tiktok_category_id' => '900009',
            'attributes' => ['100001' => 'v-ph', '100002' => 'Maple'],
        ]);
        TikTokListing::query()->updateOrCreate(
            ['product_id' => $productId],
            ['title' => 'TikTok only title', 'description' => 'TikTok only description.']
        );

        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-778', 'skus' => [['id' => 'sku-2', 'seller_sku' => 'TTA-SKU']]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.product-groups.push', $group->id), ['ids' => [$productId]]);

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $this->assertStringStartsWith('TikTok only title', $creates[0]['body']['title']);
        $this->assertSame('TikTok only description.', $creates[0]['body']['description']);
    }

    public function test_a_group_push_keeps_the_listings_own_brand(): void
    {
        $productId = $this->seedProduct();
        $group = TikTokProductGroup::create(['name' => 'Brand group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending']);
        $this->actingAs($this->manager())->put(route('ext.tiktok.product-groups.update', $group->id), [
            'name' => 'Brand group', 'tiktok_category_id' => '900009',
            'attributes' => ['100001' => 'v-ph', '100002' => 'Maple'],
        ]);
        TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['brand_id' => 'b-7', 'brand_name' => 'Boss']);

        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-779', 'skus' => [['id' => 'sku-3', 'seller_sku' => 'TTA-SKU']]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.product-groups.push', $group->id), ['ids' => [$productId]]);

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $this->assertSame('b-7', $creates[0]['body']['brand_id'] ?? null);
        $this->assertSame('900009', (string) $creates[0]['body']['category_id'], 'the group fills the category the listing leaves blank');
    }

    public function test_a_group_send_updates_a_product_already_on_tiktok_instead_of_skipping_it(): void
    {
        $productId = $this->seedProduct();
        $group = TikTokProductGroup::create(['name' => 'Live group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'tiktok_product_id' => 'tt-555', 'sync_status' => 'pushed']);
        TikTokListing::query()->create(['product_id' => $productId, 'tiktok_product_id' => 'tt-555']);

        $this->actingAs($this->manager())->post(route('ext.tiktok.product-groups.push', $group->id), ['ids' => [$productId]])
            ->assertSessionMissing('error');

        $this->assertCount(1, $this->callsFor('PUT', '/product/202309/products/tt-555'), 'the live product is updated');
        $this->assertCount(0, $this->callsFor('POST', '/product/202309/products'), 'nothing new is created on TikTok Shop');
    }

    public function test_the_selection_bars_send_updates_a_product_already_on_tiktok(): void
    {
        $productId = $this->seedProduct();
        $group = TikTokProductGroup::create(['name' => 'Live group', 'tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'tiktok_product_id' => 'tt-556', 'sync_status' => 'pushed']);
        TikTokListing::query()->create(['product_id' => $productId, 'tiktok_product_id' => 'tt-556']);
        \Extensions\tiktok\Models\TikTokCategoryTemplate::create(['category_id' => '900009', 'attributes' => [], 'fetched_at' => now()]);
        $user = $this->manager();
        $user->userGroup->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/product', 'view_tiktok/product'])->pluck('id')->all());

        $this->actingAs($user)->post(route('ext.tiktok.products.bulk_push'), ['product_ids' => [$productId]])
            ->assertSessionMissing('error');

        $this->assertCount(1, $this->callsFor('PUT', '/product/202309/products/tt-556'), 'the live product is updated');
        $this->assertCount(0, $this->callsFor('POST', '/product/202309/products'), 'nothing new is created on TikTok Shop');
    }
}
