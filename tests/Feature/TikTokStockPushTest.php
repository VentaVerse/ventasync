<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\TikTok\Models\TikTokProductGroup;
use Extensions\TikTok\Models\TikTokProductGroupProduct;
use Extensions\TikTok\Models\TikTokStore;
use Extensions\TikTok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TikTokStockPushTest extends TestCase
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
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c',
            'expires_at' => now()->addDays(3),
        ]);

        $dir = public_path('storage/catalog');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->imageFile = $dir . '/__tt-stock-test.png';
        $im = imagecreatetruecolor(4, 4);
        imagepng($im, $this->imageFile);
        imagedestroy($im);

        $test = $this;
        $fake = new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function get(string $appKey, string $appSecret, string $accessToken, string $path, array $extraParams = [], ?string $shopCipher = null): array
            {
                return $this->test->answer('GET', $path, $extraParams);
            }
            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            {
                return $this->test->answer('POST', $path, $body);
            }
            public function put(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            {
                return $this->test->answer('PUT', $path, $body);
            }
            public function uploadImage(string $appKey, string $appSecret, string $accessToken, string $imageData, ?string $shopCipher = null): array
            {
                return $this->test->answer('POST', '/product/202309/images/upload', ['bytes' => strlen($imageData)]);
            }
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

        return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['uri' => 'img-uri-' . count($this->sentCalls)]]];
    }

    private function callsFor(string $method, string $path): array
    {
        return array_values(array_filter($this->sentCalls, fn ($c) => $c['method'] === $method && $c['path'] === $path));
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok stock pushers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_tiktok/product_group', 'view_tiktok/product_group', 'manage_tiktok/listing', 'view_tiktok/listing', 'manage_tiktok/product', 'view_tiktok/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedTwoAxisProduct(): array
    {
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table('products')->insertGetId([
            'sku' => 'TTV', 'quantity' => 10, 'price' => 1000,
            'status' => 1, 'image' => 'catalog/__tt-stock-test.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'brand_id' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Two axis TikTok product with a long enough name', 'description' => 'Colors and sizes.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        $ovIds = [];
        $povIds = [];
        foreach (['Color' => ['Red', 'Black'], 'Size' => ['S', 'M']] as $axis => $values) {
            $variationTypeId = (int) DB::table('variation_types')->insertGetId(['type' => 'select', 'sort_order' => 0]);
            DB::table('variation_type_descriptions')->insert(['variation_type_id' => $variationTypeId, 'language_id' => $langId, 'name' => $axis]);
            $productVariationTypeId = (int) DB::table('product_variation_types')->insertGetId([
                'product_id' => $productId, 'variation_type_id' => $variationTypeId, 'value' => '', 'required' => 0,
            ]);
            foreach ($values as $i => $name) {
                $valueId = (int) DB::table('variation_values')->insertGetId(['variation_type_id' => $variationTypeId, 'image' => '', 'sort_order' => $i]);
                DB::table('variation_value_descriptions')->insert([
                    'variation_value_id' => $valueId, 'language_id' => $langId, 'variation_type_id' => $variationTypeId, 'name' => $name,
                ]);
                $povIds[$name] = (int) DB::table('product_variation_values')->insertGetId([
                    'product_variation_type_id' => $productVariationTypeId, 'product_id' => $productId,
                    'variation_type_id' => $variationTypeId, 'variation_value_id' => $valueId,
                    'sku' => 'TTV-' . strtoupper($name), 'quantity' => 99, 'subtract_stock' => 1,
                    'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
                    'weight' => 0, 'weight_prefix' => '+',
                    'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0,
                    'absolute_cost' => 0, 'cost_prefix' => '+', 'absolute_price' => 0,
                ]);
                $ovIds[$name] = $valueId;
            }
        }

        $qty = 1;
        foreach (['Red', 'Black'] as $color) {
            foreach (['S', 'M'] as $size) {
                $comboId = (int) DB::table('product_variations')->insertGetId([
                    'product_id' => $productId, 'sku' => "TTV-{$color}-{$size}", 'quantity' => $qty,
                    'absolute_price' => 1000 + $qty * 100, 'image' => $color === 'Red' ? 'catalog/__tt-stock-test.png' : null, 'status' => 1,
                    'sort_order' => $qty, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('product_variation_parts')->insert([
                    ['product_variation_id' => $comboId, 'product_variation_value_id' => $povIds[$color]],
                    ['product_variation_id' => $comboId, 'product_variation_value_id' => $povIds[$size]],
                ]);
                $qty++;
            }
        }

        return ['product_id' => $productId, 'ovIds' => $ovIds];
    }

    public function test_the_cron_pushes_every_combination_its_own_quantity(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Stock group']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-1', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => json_encode(['TTV-Red-S' => 's1', 'TTV-Red-M' => 's2', 'TTV-Black-S' => 's3', 'TTV-Black-M' => 's4']),
        ]);

        $this->artisan('tiktok:push-stock')->assertExitCode(0);

        $calls = $this->callsFor('POST', '/product/202309/products/tt-1/inventory/update');
        $this->assertCount(1, $calls);
        $byId = collect($calls[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['inventory'][0]['quantity']);
        $this->assertSame(['s1' => 1, 's2' => 2, 's3' => 3, 's4' => 4], $byId->all());
    }

    public function test_a_legacy_map_keyed_by_option_value_id_is_rebuilt_by_seller_sku_before_the_push(): void
    {
        ['product_id' => $productId, 'ovIds' => $ovIds] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Legacy group']);
        $pivot = TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-2', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => json_encode([$ovIds['Red'] => 'legacy-red', $ovIds['Black'] => 'legacy-black']),
        ]);
        $this->callResponses['GET /product/202309/products/tt-2'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => [
            'id' => 'tt-2', 'status' => 'ACTIVATE', 'title' => 'Two axis',
            'skus' => array_map(fn ($sku, $id) => ['id' => $id, 'seller_sku' => $sku, 'price' => ['sale_price' => '1000'], 'inventory' => [['quantity' => 1]]],
                ['TTV-Red-S', 'TTV-Red-M', 'TTV-Black-S', 'TTV-Black-M'], ['s1', 's2', 's3', 's4']),
        ]]];

        $this->artisan('tiktok:push-stock')->assertExitCode(0);

        $calls = $this->callsFor('POST', '/product/202309/products/tt-2/inventory/update');
        $this->assertCount(1, $calls);
        $byId = collect($calls[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['inventory'][0]['quantity']);
        $this->assertSame(['s1' => 1, 's2' => 2, 's3' => 3, 's4' => 4], $byId->all());
        $this->assertSame(['TTV-Red-S' => 's1', 'TTV-Red-M' => 's2', 'TTV-Black-S' => 's3', 'TTV-Black-M' => 's4'], json_decode((string) $pivot->fresh()->tiktok_sku_id, true));
    }

    public function test_the_products_page_pushes_stock_through_the_same_resolver(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Page group']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-3', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => json_encode(['TTV-Red-S' => 's1', 'TTV-Red-M' => 's2', 'TTV-Black-S' => 's3', 'TTV-Black-M' => 's4']),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.bulk_push_stock'), ['product_ids' => [$productId]])
            ->assertSessionHas('status');

        $calls = $this->callsFor('POST', '/product/202309/products/tt-3/inventory/update');
        $this->assertCount(1, $calls);
        $byId = collect($calls[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['inventory'][0]['quantity']);
        $this->assertSame(['s1' => 1, 's2' => 2, 's3' => 3, 's4' => 4], $byId->all());
    }

    public function test_a_push_names_every_axis_on_every_sku_and_stores_the_map_by_seller_sku(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Push group']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending',
        ]);
        \Extensions\TikTok\Models\TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_category_id' => '900001']);
        $this->callResponses['POST /product/202309/products'] = function (array $body) {
            $skus = [];
            foreach ($body['skus'] as $i => $sku) {
                $skus[] = ['id' => 'new-' . ($i + 1), 'seller_sku' => $sku['seller_sku']];
            }

            return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['product_id' => 'tt-new', 'skus' => $skus]]];
        };

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.bulk_push'), ['product_ids' => [$productId]]);

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $skus = $creates[0]['body']['skus'];
        $this->assertCount(4, $skus);
        $this->assertSame('TTV-Red-S', $skus[0]['seller_sku']);
        $this->assertSame(
            [['name' => 'Color', 'value_name' => 'Red'], ['name' => 'Size', 'value_name' => 'S']],
            array_map(fn ($a) => array_diff_key($a, ['sku_img' => true]), $skus[0]['sales_attributes'])
        );
        $this->assertSame('1', $skus[0]['inventory'][0]['quantity'] === 1 ? '1' : (string) $skus[0]['inventory'][0]['quantity']);
        $this->assertSame('1100', $skus[0]['price']['amount']);
        $this->assertArrayHasKey('sku_img', $skus[0]['sales_attributes'][0], 'Red carries its own picture, on its first sales attribute where TikTok Shop reads it.');
        $this->assertArrayNotHasKey('sku_img', $skus[0], 'never on the SKU itself, where TikTok Shop ignores it');
        $this->assertArrayNotHasKey('sku_img', $skus[0]['sales_attributes'][1]);
        $this->assertArrayNotHasKey('sku_img', $skus[2]['sales_attributes'][0], 'Black has none.');

        $pivot = TikTokProductGroupProduct::where('product_id', $productId)->first();
        $this->assertSame('tt-new', $pivot->tiktok_product_id);
        $this->assertSame(
            ['TTV-Red-S' => 'new-1', 'TTV-Red-M' => 'new-2', 'TTV-Black-S' => 'new-3', 'TTV-Black-M' => 'new-4'],
            json_decode($pivot->tiktok_sku_id, true)
        );
    }

    public function test_the_price_cron_prices_by_the_listing_rule_never_the_group(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Price group']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-9', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => json_encode(['TTV-Red-S' => 's1', 'TTV-Red-M' => 's2', 'TTV-Black-S' => 's3', 'TTV-Black-M' => 's4']),
        ]);

        $this->artisan('tiktok:push-price')->assertExitCode(0);

        $calls = $this->callsFor('POST', '/product/202309/products/tt-9/prices/update');
        $this->assertCount(1, $calls);
        $byId = collect($calls[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['price']['amount']);
        $this->assertSame(['s1' => '1100', 's2' => '1200', 's3' => '1300', 's4' => '1400'], $byId->all(), 'no listing rule: the catalog prices, not the group\'s 10%');
        $this->assertSame([], $this->callsFor('POST', '/product/202309/products/tt-9/inventory/update'), 'the price row never sends stock');

        (new \Extensions\TikTok\Models\TikTokListing())->forceFill(['product_id' => $productId, 'markup_percent' => 0, 'markup_fixed' => 50])->save();
        $this->artisan('tiktok:push-price')->assertExitCode(0);
        $calls = $this->callsFor('POST', '/product/202309/products/tt-9/prices/update');
        $this->assertCount(2, $calls);
        $byId = collect($calls[1]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['price']['amount']);
        $this->assertSame(['s1' => '1150', 's2' => '1250', 's3' => '1350', 's4' => '1450'], $byId->all());
    }

    public function test_the_price_cron_starts_a_simple_product_from_the_listings_own_price(): void
    {
        $simple = DB::table('products')->insertGetId([
            'sku' => 'TTS', 'quantity' => 4, 'price' => 300,
            'status' => 1, 'image' => 'catalog/__tt-stock-test.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'brand_id' => 0, 'date_added' => now(), 'date_modified' => now(),
        ]);
        ['product_id' => $varied] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Own price group']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $simple,
            'tiktok_product_id' => 'tt-s', 'tiktok_sku_id' => 'single-1', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
        ]);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $varied,
            'tiktok_product_id' => 'tt-9', 'tiktok_sku_id' => json_encode(self::SKU_IDS), 'sync_status' => 'pushed', 'last_pushed_at' => now(),
        ]);
        (new \Extensions\TikTok\Models\TikTokListing())->forceFill(['product_id' => $simple, 'price' => 500, 'markup_percent' => 10])->save();
        (new \Extensions\TikTok\Models\TikTokListing())->forceFill(['product_id' => $varied, 'price' => 5, 'markup_percent' => 10])->save();

        $this->artisan('tiktok:push-price')->assertExitCode(0);

        $calls = $this->callsFor('POST', '/product/202309/products/tt-s/prices/update');
        $this->assertCount(1, $calls);
        $this->assertSame([['id' => 'single-1', 'price' => ['amount' => '550', 'currency' => 'PHP']]], $calls[0]['body']['skus']);

        $calls = $this->callsFor('POST', '/product/202309/products/tt-9/prices/update');
        $byId = collect($calls[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['price']['amount']);
        $this->assertSame(['s1' => '1210', 's2' => '1320', 's3' => '1430', 's4' => '1540'], $byId->all(), 'each variation from the catalog, the stored Price ignored');
    }

    public function test_another_stores_group_never_reaches_this_store(): void
    {
        $main = TikTokStore::query()->orderBy('id')->first();
        $outlet = TikTokStore::create([
            'mode' => 'production', 'store_name' => 'Outlet', 'app_key' => 'k2', 'app_secret' => 's2',
            'access_token' => 't2', 'refresh_token' => 'r2', 'shop_cipher' => 'c2', 'expires_at' => now()->addDays(3),
        ]);
        $pid = 424242;
        $other = TikTokProductGroup::create(['store_id' => $outlet->id, 'name' => 'Outlet group']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $other->id, 'product_id' => $pid, 'sync_status' => 'pending']);

        $this->app->instance('tiktok.route-store', $main);
        $rule = \Extensions\TikTok\Commands\TikTokPushPrice::priceRule([(object) ['product_id' => $pid]]);
        $this->assertSame(1000.0, $rule(1000.0, (object) ['product_id' => $pid]), 'no group on this store: the catalog price');
        $this->app->forgetInstance('tiktok.route-store');
        $this->assertSame((int) $main->id, (int) TikTokStore::defaultStore()->id);
    }

    private const SKU_IDS = ['TTV-Red-S' => 's1', 'TTV-Red-M' => 's2', 'TTV-Black-S' => 's3', 'TTV-Black-M' => 's4'];

    private function categoryWithSpecification(): void
    {
        \Extensions\TikTok\Models\TikTokCategoryTemplate::query()->create([
            'category_id' => '900001', 'fetched_at' => now(),
            'attributes' => [['id' => '100089', 'name' => 'Specification', 'type' => 'SALES_PROPERTY', 'values' => []]],
        ]);
    }

    private function liveItem(string $ttId, array $color, ?array $size, array $skuIds = self::SKU_IDS): array
    {
        $skus = [];
        foreach ($skuIds as $seller => $id) {
            [, $c, $s] = explode('-', $seller);
            $attributes = [['id' => $color[0], 'name' => $color[1], 'value_id' => $color[2][$c][0], 'value_name' => $color[2][$c][1]]];
            if ($size !== null) {
                $attributes[] = ['id' => $size[0], 'name' => $size[1], 'value_id' => $size[2][$s][0], 'value_name' => $size[2][$s][1]];
            }
            $skus[] = ['id' => $id, 'seller_sku' => $seller, 'price' => ['sale_price' => '1000'], 'inventory' => [['quantity' => 1]], 'sales_attributes' => $attributes];
        }

        return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['id' => $ttId, 'status' => 'ACTIVATE', 'title' => 'Two axis', 'skus' => $skus]]];
    }

    private function linkedInGroup(int $productId, string $ttId, array $map = self::SKU_IDS): TikTokProductGroupProduct
    {
        $group = TikTokProductGroup::create(['name' => 'Rename group ' . $ttId]);

        return TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => $ttId, 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => json_encode($map),
        ]);
    }

    private function updateThroughGroup(TikTokProductGroupProduct $pivot): \Illuminate\Testing\TestResponse
    {
        \Extensions\TikTok\Models\TikTokListing::query()->updateOrCreate(['product_id' => $pivot->product_id], [
            'tiktok_category_id' => '900001', 'tiktok_product_id' => $pivot->tiktok_product_id, 'tiktok_sku_id' => $pivot->tiktok_sku_id,
        ]);

        return $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.bulk_push'), ['product_ids' => [$pivot->product_id]]);
    }

    private static function withoutPicture(array $attributes): array
    {
        return array_map(fn ($a) => array_diff_key($a, ['sku_img' => true]), $attributes);
    }

    private function editedSkus(string $ttId): array
    {
        $puts = $this->callsFor('PUT', '/product/202309/products/' . $ttId);
        $this->assertCount(1, $puts, 'one edit reaches TikTok Shop');

        return collect($puts[0]['body']['skus'])->keyBy('seller_sku')->all();
    }

    public function test_an_update_renames_a_type_the_seller_named_and_keeps_every_sku(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        $pivot = $this->linkedInGroup($productId, 'tt-r1');
        $this->callResponses['GET /product/202309/products/tt-r1'] = $this->liveItem('tt-r1',
            ['7301', 'Shade', ['Red' => ['8301', 'Crimson'], 'Black' => ['8302', 'Onyx']]],
            ['7302', 'Talla', ['S' => ['9001', 'S'], 'M' => ['9002', 'M']]]);

        $this->updateThroughGroup($pivot);

        $skus = $this->editedSkus('tt-r1');
        $this->assertSame(self::SKU_IDS, array_map(fn ($s) => $s['id'] ?? null, $skus), 'no variation is deleted and re-created');
        $this->assertSame([['name' => 'Color', 'value_name' => 'Red'], ['name' => 'Size', 'value_name' => 'S']], self::withoutPicture($skus['TTV-Red-S']['sales_attributes']));
        $this->assertSame([['name' => 'Color', 'value_name' => 'Black'], ['name' => 'Size', 'value_name' => 'M']], $skus['TTV-Black-M']['sales_attributes']);
        $this->assertSame('pushed', $pivot->fresh()->sync_status);
    }

    public function test_an_update_keeps_the_name_of_a_type_the_category_defines(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        $pivot = $this->linkedInGroup($productId, 'tt-r2');
        $this->callResponses['GET /product/202309/products/tt-r2'] = $this->liveItem('tt-r2',
            ['7301', 'Shade', ['Red' => ['8301', 'Red'], 'Black' => ['8302', 'Black']]],
            ['100089', 'Specification', ['S' => ['9001', 'S'], 'M' => ['9002', 'Medium']]]);

        $this->updateThroughGroup($pivot);

        $skus = $this->editedSkus('tt-r2');
        $this->assertSame(['name' => 'Color', 'value_name' => 'Red'], self::withoutPicture($skus['TTV-Red-S']['sales_attributes'])[0], 'the seller\'s own type is renamed');
        $this->assertSame(['id' => '100089', 'value_id' => '9001', 'value_name' => 'S'], $skus['TTV-Red-S']['sales_attributes'][1]);
        $this->assertSame(['id' => '100089', 'value_name' => 'M'], $skus['TTV-Red-M']['sales_attributes'][1], 'the value takes the catalog\'s name, the type keeps its own');
        foreach ($skus as $sku) {
            $this->assertArrayNotHasKey('name', $sku['sales_attributes'][1]);
        }
    }

    public function test_an_update_sends_no_rename_when_the_names_already_match(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        $pivot = $this->linkedInGroup($productId, 'tt-r3');
        $this->callResponses['GET /product/202309/products/tt-r3'] = $this->liveItem('tt-r3',
            ['7301', 'Color', ['Red' => ['8301', 'Red'], 'Black' => ['8302', 'Black']]],
            ['7302', 'Size', ['S' => ['9001', 'S'], 'M' => ['9002', 'M']]]);

        $this->updateThroughGroup($pivot);

        $skus = $this->editedSkus('tt-r3');
        $this->assertSame([['id' => '7301', 'value_id' => '8301', 'value_name' => 'Red'], ['id' => '7302', 'value_id' => '9001', 'value_name' => 'S']], self::withoutPicture($skus['TTV-Red-S']['sales_attributes']));
        foreach ($skus as $sku) {
            foreach ($sku['sales_attributes'] as $attribute) {
                $this->assertArrayNotHasKey('name', $attribute);
                $this->assertArrayHasKey('value_id', $attribute);
            }
        }
    }

    public function test_the_stored_map_follows_the_item_after_a_rename(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        $pivot = $this->linkedInGroup($productId, 'tt-r4', ['TTV-Red-S' => 's1', 'TTV-Red-M' => 'gone-2', 'TTV-Black-S' => 's3']);
        $before = $this->liveItem('tt-r4',
            ['7301', 'Shade', ['Red' => ['8301', 'Crimson'], 'Black' => ['8302', 'Onyx']]],
            ['7302', 'Talla', ['S' => ['9001', 'S'], 'M' => ['9002', 'M']]]);
        $after = $this->liveItem('tt-r4',
            ['7401', 'Color', ['Red' => ['8401', 'Red'], 'Black' => ['8402', 'Black']]],
            ['7402', 'Size', ['S' => ['9401', 'S'], 'M' => ['9402', 'M']]]);
        $edited = false;
        $this->callResponses['GET /product/202309/products/tt-r4'] = function () use (&$edited, $before, $after) {
            return $edited ? $after : $before;
        };
        $this->callResponses['PUT /product/202309/products/tt-r4'] = function () use (&$edited) {
            $edited = true;

            return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['product_id' => 'tt-r4']]];
        };

        $this->updateThroughGroup($pivot);

        $this->assertSame(self::SKU_IDS, array_map(fn ($s) => $s['id'] ?? null, $this->editedSkus('tt-r4')));
        $this->assertSame(self::SKU_IDS, json_decode((string) \Extensions\TikTok\Models\TikTokListing::where('product_id', $productId)->value('tiktok_sku_id'), true));
        $this->assertSame(self::SKU_IDS, json_decode((string) \Extensions\TikTok\Models\TikTokListing::where('product_id', $productId)->value('tiktok_sku_id'), true));

        $this->artisan('tiktok:push-stock')->assertExitCode(0);
        $stock = $this->callsFor('POST', '/product/202309/products/tt-r4/inventory/update');
        $this->assertCount(1, $stock);
        $this->assertSame(['s1' => 1, 's2' => 2, 's3' => 3, 's4' => 4], collect($stock[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['inventory'][0]['quantity'])->all());
    }

    public function test_the_listing_page_update_renames_the_same_way(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        \Extensions\TikTok\Models\TikTokListing::create([
            'product_id' => $productId, 'tiktok_product_id' => 'tt-l1', 'tiktok_category_id' => '900001',
            'tiktok_sku_id' => json_encode(self::SKU_IDS),
        ]);
        $this->callResponses['GET /product/202309/products/tt-l1'] = $this->liveItem('tt-l1',
            ['7301', 'Shade', ['Red' => ['8301', 'Crimson'], 'Black' => ['8302', 'Onyx']]],
            ['7302', 'Talla', ['S' => ['9001', 'S'], 'M' => ['9002', 'M']]]);

        $this->actingAs($this->manager())->post(route('ext.tiktok.listings.push_update', $productId))
            ->assertSessionHas('status', 'Update pushed.');

        $skus = $this->editedSkus('tt-l1');
        $this->assertSame(self::SKU_IDS, array_map(fn ($s) => $s['id'] ?? null, $skus));
        $this->assertSame([['name' => 'Color', 'value_name' => 'Black'], ['name' => 'Size', 'value_name' => 'S']], $skus['TTV-Black-S']['sales_attributes']);
        $this->assertSame(self::SKU_IDS, json_decode((string) \Extensions\TikTok\Models\TikTokListing::where('product_id', $productId)->value('tiktok_sku_id'), true));
    }

    public function test_a_rename_that_cannot_be_paired_is_skipped_and_the_update_goes_through(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        \Extensions\TikTok\Models\TikTokListing::create([
            'product_id' => $productId, 'tiktok_product_id' => 'tt-l2', 'tiktok_category_id' => '900001',
            'tiktok_sku_id' => json_encode(self::SKU_IDS),
        ]);
        $this->callResponses['GET /product/202309/products/tt-l2'] = $this->liveItem('tt-l2',
            ['7301', 'Shade', ['Red' => ['8301', 'Crimson'], 'Black' => ['8302', 'Onyx']]], null,
            ['TTV-Red-S' => 's1', 'TTV-Black-S' => 's3']);
        $warning = 'Variation names were not changed: the item on TikTok Shop has 1 variation type (Shade) and the product has 2 (Color, Size), and 2 new variations were not added.';

        $this->actingAs($this->manager())->post(route('ext.tiktok.listings.push_update', $productId))
            ->assertSessionHas('warning', 'Update pushed. ' . $warning);

        $skus = $this->editedSkus('tt-l2');
        $this->assertSame(['TTV-Red-S' => 's1', 'TTV-Black-S' => 's3'], array_map(fn ($s) => $s['id'] ?? null, $skus));
        $this->assertSame([['id' => '7301', 'value_id' => '8301', 'value_name' => 'Crimson']], self::withoutPicture($skus['TTV-Red-S']['sales_attributes']), 'kept as TikTok holds it');
        $this->assertSame([['id' => '7301', 'value_id' => '8302', 'value_name' => 'Onyx']], $skus['TTV-Black-S']['sales_attributes']);
        $this->assertNull(\Extensions\TikTok\Models\TikTokListing::where('product_id', $productId)->value('last_push_error'), 'the update succeeded, so the row carries no error');
    }

    public function test_a_rename_tiktok_refuses_is_sent_again_under_the_items_own_names(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        $pivot = $this->linkedInGroup($productId, 'tt-r5');
        $this->callResponses['GET /product/202309/products/tt-r5'] = $this->liveItem('tt-r5',
            ['7301', 'Shade', ['Red' => ['8301', 'Crimson'], 'Black' => ['8302', 'Onyx']]],
            ['7302', 'Talla', ['S' => ['9001', 'S'], 'M' => ['9002', 'M']]]);
        $this->callResponses['PUT /product/202309/products/tt-r5'] = function (array $body) {
            return isset($body['skus'][0]['sales_attributes'][0]['name'])
                ? ['ok' => true, 'status' => 200, 'body' => ['code' => 12019019, 'message' => 'sale property is invalid']]
                : ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['product_id' => 'tt-r5']]];
        };

        $response = $this->updateThroughGroup($pivot);

        $puts = $this->callsFor('PUT', '/product/202309/products/tt-r5');
        $this->assertCount(2, $puts);
        $second = collect($puts[1]['body']['skus'])->keyBy('seller_sku')->all();
        $this->assertSame(self::SKU_IDS, array_map(fn ($s) => $s['id'] ?? null, $second));
        $this->assertSame([['id' => '7301', 'value_id' => '8301', 'value_name' => 'Crimson'], ['id' => '7302', 'value_id' => '9001', 'value_name' => 'S']], self::withoutPicture($second['TTV-Red-S']['sales_attributes']));
        $summary = (string) (session('warning') ?? session('status'));
        $this->assertStringContainsString('Variation names were not changed: TikTok Shop refused them (', $summary);
        $this->assertStringContainsString('sale property is invalid', $summary);
        $this->assertNull(\Extensions\TikTok\Models\TikTokListing::where('product_id', $productId)->value('last_push_error'), 'the update succeeded, so the row carries no error');
        $this->assertSame(self::SKU_IDS, json_decode((string) \Extensions\TikTok\Models\TikTokListing::where('product_id', $productId)->value('tiktok_sku_id'), true));
    }

    public function test_an_item_that_cannot_be_read_is_not_edited(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        $pivot = $this->linkedInGroup($productId, 'tt-r6');
        $this->callResponses['GET /product/202309/products/tt-r6'] = ['ok' => false, 'status' => 500, 'body' => ['code' => 36009003, 'message' => 'Internal error']];

        $this->updateThroughGroup($pivot);

        $this->assertSame([], $this->callsFor('PUT', '/product/202309/products/tt-r6'));
        $this->assertSame([], $this->callsFor('POST', '/product/202309/images/upload'), 'not even a picture');
        $this->assertSame(
            'TikTok Shop did not say which variations this item has (TikTok Shop did not answer: Internal error), so nothing was sent.',
            \Extensions\TikTok\Models\TikTokListing::where('product_id', $productId)->value('last_push_error')
        );
    }

    public function test_a_new_item_is_created_as_before_without_reading_the_shop(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        $group = TikTokProductGroup::create(['name' => 'Create group']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending']);
        \Extensions\TikTok\Models\TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_category_id' => '900001']);
        \Extensions\TikTok\Models\TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_category_id' => '900001']);
        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-c1', 'skus' => array_map(fn ($seller, $id) => ['id' => $id, 'seller_sku' => $seller], array_keys(self::SKU_IDS), self::SKU_IDS)]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.bulk_push'), ['product_ids' => [$productId]]);

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        foreach ($creates[0]['body']['skus'] as $sku) {
            $this->assertArrayNotHasKey('id', $sku);
            $this->assertSame(['Color', 'Size'], array_column($sku['sales_attributes'], 'name'));
        }
        $this->assertSame([], array_filter($this->sentCalls, fn ($c) => $c['method'] === 'GET' && str_starts_with($c['path'], '/product/202309/products/')));
        $this->assertSame(self::SKU_IDS, json_decode((string) TikTokProductGroupProduct::where('product_id', $productId)->value('tiktok_sku_id'), true));
    }

    public function test_the_heal_keeps_a_sku_map_longer_than_255_characters_on_the_group_pivot(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Wide group']);
        $pivot = TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-7', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => null,
        ]);
        $skus = [];
        foreach (['TTV-Red-S', 'TTV-Red-M', 'TTV-Black-S', 'TTV-Black-M', 'TTV-Red-L', 'TTV-Red-XL', 'TTV-Black-L', 'TTV-Black-XL', 'TTV-Blue-S', 'TTV-Blue-M', 'TTV-Blue-L', 'TTV-Blue-XL'] as $i => $sellerSku) {
            $skus[] = ['id' => '17290000000000' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT), 'seller_sku' => $sellerSku, 'price' => ['sale_price' => '1000'], 'inventory' => [['quantity' => 1]]];
        }
        $this->callResponses['GET /product/202309/products/tt-7'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['id' => 'tt-7', 'status' => 'ACTIVATE', 'title' => 'Wide', 'skus' => $skus]]];

        $this->artisan('tiktok:push-stock')->assertExitCode(0);

        $stored = (string) $pivot->fresh()->tiktok_sku_id;
        $this->assertGreaterThan(255, strlen($stored), 'the whole map is kept, not a truncated prefix');
        $map = json_decode($stored, true);
        $this->assertIsArray($map, 'the stored map still parses');
        $this->assertCount(12, $map);
        $this->assertSame('1729000000000000001', $map['TTV-Red-S']);
        $this->assertSame('1729000000000000012', $map['TTV-Blue-XL']);

        $this->assertCount(1, $this->callsFor('POST', '/product/202309/products/tt-7/inventory/update'), 'the catalog\'s four go up');
        $this->assertNotSame('error', $pivot->fresh()->sync_status);
    }
}
