<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\TikTok\Models\TikTokCategory;
use Extensions\TikTok\Models\TikTokCategoryTemplate;
use Extensions\TikTok\Models\TikTokListing;
use Extensions\TikTok\Models\TikTokProductGroup;
use Extensions\TikTok\Models\TikTokProductGroupProduct;
use Extensions\TikTok\Models\TikTokStore;
use Extensions\TikTok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TikTokAttributesTest extends TestCase
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
        $this->app->register(\Extensions\TikTok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');

        TikTokStore::create([
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
        $group->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/product_group', 'view_tiktok/product_group', 'manage_tiktok/listing_template', 'view_tiktok/listing_template', 'manage_tiktok/product', 'view_tiktok/product'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function applyTemplate(int $productId, array $attributes): void
    {
        $template = \Extensions\TikTok\Models\TikTokListingTemplate::query()->create([
            'store_id' => \Extensions\TikTok\Models\TikTokStore::defaultStore()->id, 'name' => 'Answers ' . $productId, 'tiktok_category_id' => '900009',
        ]);
        foreach ($attributes as $key => $value) {
            $template->templateAttributes()->create(['attribute_key' => (string) $key, 'value' => $value]);
        }
        $this->actingAs($this->manager())->post(route('ext.tiktok.listing-templates.apply'), ['product_ids' => [$productId], 'template_id' => $template->id])
            ->assertSessionHas('status');
    }

    private function seedProduct(): int
    {
        $langId = (int) config('catalog.default_language_id');
        $productId = DB::table('products')->insertGetId([
            'sku' => 'TTA-SKU', 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/__tt-attr-test.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'brand_id' => 0, 'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Attribute product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_opening_a_template_reads_and_caches_the_sheet_and_lists_only_product_properties(): void
    {
        $template = \Extensions\TikTok\Models\TikTokListingTemplate::query()->create(['store_id' => \Extensions\TikTok\Models\TikTokStore::defaultStore()->id, 'name' => 'Sheet template', 'tiktok_category_id' => '900009']);

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.listing-templates.edit', ['template' => $template->id]));

        $r->assertOk();
        $r->assertSee('Brand Origin');
        $r->assertSee('Material');
        $r->assertDontSee('TikTok attribute 100003');
        $this->assertCount(1, $this->callsFor('GET', '/product/202309/categories/900009/attributes'));
        $this->assertNotNull(TikTokCategoryTemplate::where('category_id', '900009')->first());

        $this->actingAs($this->manager())->get(route('ext.tiktok.listing-templates.edit', ['template' => $template->id]))->assertOk();
        $this->assertCount(1, $this->callsFor('GET', '/product/202309/categories/900009/attributes'));
    }

    public function test_a_templates_answers_ride_the_push_as_product_attributes_and_the_truth_lands_on_the_listing(): void
    {
        $productId = $this->seedProduct();
        $this->applyTemplate($productId, ['100001' => 'v-ph', '100002' => 'Maple']);
        $this->assertEquals(['100001' => 'v-ph', '100002' => 'Maple'], TikTokListing::where('product_id', $productId)->first()->attribute_values);

        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-777', 'skus' => [['id' => 'sku-1', 'seller_sku' => 'TTA-SKU']]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.bulk_push'), ['product_ids' => [$productId]]);

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
    }

    public function test_a_push_sends_the_listings_own_words_not_the_catalogs(): void
    {
        $productId = $this->seedProduct();
        $this->applyTemplate($productId, ['100001' => 'v-ph', '100002' => 'Maple']);
        TikTokListing::query()->updateOrCreate(
            ['product_id' => $productId],
            ['title' => 'TikTok only title', 'description' => 'TikTok only description.']
        );

        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-778', 'skus' => [['id' => 'sku-2', 'seller_sku' => 'TTA-SKU']]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.bulk_push'), ['product_ids' => [$productId]]);

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $this->assertStringStartsWith('TikTok only title', $creates[0]['body']['title']);
        $this->assertSame('TikTok only description.', $creates[0]['body']['description']);
    }

    public function test_a_template_saves_its_brand_and_gives_it_to_a_listing(): void
    {
        $store = \Extensions\TikTok\Models\TikTokStore::defaultStore();
        $this->actingAs($this->manager())->post(route('ext.tiktok.listing-templates.store'), [
            'name' => 'Boss pedals', 'tiktok_category_id' => '900009', 'brand_id' => 'b-7', 'brand_name' => 'Boss',
        ])->assertSessionHasNoErrors();
        $template = \Extensions\TikTok\Models\TikTokListingTemplate::query()->where('name', 'Boss pedals')->firstOrFail();
        $this->assertSame(['b-7', 'Boss'], [$template->brand_id, $template->brand_name]);

        $this->actingAs($this->manager())->get(route('ext.tiktok.listing-templates.edit', ['template' => $template->id]))
            ->assertOk()->assertSee('Category and brand')->assertSee('name="brand_id" data-combo-value value="b-7"', false);

        $productId = $this->seedProduct();
        $this->actingAs($this->manager())->post(route('ext.tiktok.listing-templates.apply'), ['product_ids' => [$productId], 'template_id' => $template->id])
            ->assertSessionHas('status');
        $listing = TikTokListing::query()->where('store_id', $store->id)->where('product_id', $productId)->firstOrFail();
        $this->assertSame(['b-7', 'Boss'], [$listing->brand_id, $listing->brand_name]);
    }

    public function test_a_push_keeps_the_listings_own_brand(): void
    {
        $productId = $this->seedProduct();
        $this->applyTemplate($productId, ['100001' => 'v-ph', '100002' => 'Maple']);
        TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['brand_id' => 'b-7', 'brand_name' => 'Boss']);

        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-779', 'skus' => [['id' => 'sku-3', 'seller_sku' => 'TTA-SKU']]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.bulk_push'), ['product_ids' => [$productId]]);

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $this->assertSame('b-7', $creates[0]['body']['brand_id'] ?? null);
        $this->assertSame('900009', (string) $creates[0]['body']['category_id'], 'the template put the category on the listing');
    }

    public function test_the_selection_bars_send_updates_a_product_already_on_tiktok(): void
    {
        $productId = $this->seedProduct();
        $group = TikTokProductGroup::create(['name' => 'Live group']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'tiktok_product_id' => 'tt-556', 'sync_status' => 'pushed']);
        TikTokListing::query()->create(['product_id' => $productId, 'tiktok_product_id' => 'tt-556', 'tiktok_category_id' => '900009']);
        \Extensions\TikTok\Models\TikTokCategoryTemplate::create(['category_id' => '900009', 'attributes' => [], 'fetched_at' => now()]);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.tiktok.products.bulk_push'), ['product_ids' => [$productId]])
            ->assertSessionMissing('error');

        $this->assertCount(1, $this->callsFor('PUT', '/product/202309/products/tt-556'), 'the live product is updated');
        $this->assertCount(0, $this->callsFor('POST', '/product/202309/products'), 'nothing new is created on TikTok Shop');
    }
}
