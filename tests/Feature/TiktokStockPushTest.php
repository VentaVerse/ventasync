<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TiktokStockPushTest extends TestCase
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
            Permission::whereIn('key', ['manage_tiktok/product_group', 'view_tiktok/product_group', 'manage_tiktok/listing', 'view_tiktok/listing'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedTwoAxisProduct(): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'TTV-1', 'sku' => 'TTV', 'quantity' => 10, 'price' => 1000,
            'status' => 1, 'image' => 'catalog/__tt-stock-test.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Two axis TikTok product with a long enough name', 'description' => 'Colours and sizes.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        $ovIds = [];
        $povIds = [];
        foreach (['Colour' => ['Red', 'Black'], 'Size' => ['S', 'M']] as $axis => $values) {
            $optionId = (int) DB::table($pfx . 'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
            DB::table($pfx . 'option_description')->insert(['option_id' => $optionId, 'language_id' => $langId, 'name' => $axis]);
            $productOptionId = (int) DB::table($pfx . 'product_option')->insertGetId([
                'product_id' => $productId, 'option_id' => $optionId, 'value' => '', 'required' => 0,
            ]);
            foreach ($values as $i => $name) {
                $valueId = (int) DB::table($pfx . 'option_value')->insertGetId(['option_id' => $optionId, 'image' => '', 'sort_order' => $i]);
                DB::table($pfx . 'option_value_description')->insert([
                    'option_value_id' => $valueId, 'language_id' => $langId, 'option_id' => $optionId, 'name' => $name,
                ]);
                $povIds[$name] = (int) DB::table($pfx . 'product_option_value')->insertGetId([
                    'product_option_id' => $productOptionId, 'product_id' => $productId,
                    'option_id' => $optionId, 'option_value_id' => $valueId,
                    'sku' => 'TTV-' . strtoupper($name), 'quantity' => 99, 'subtract' => 1,
                    'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
                    'weight' => 0, 'weight_prefix' => '+',
                    'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0,
                    'absolute_cost' => 0, 'cost_prefix' => '+', 'absolute_price' => 0,
                ]);
                $ovIds[$name] = $valueId;
            }
        }

        $qty = 1;
        foreach (['Red', 'Black'] as $colour) {
            foreach (['S', 'M'] as $size) {
                $comboId = (int) DB::table('product_option_combinations')->insertGetId([
                    'product_id' => $productId, 'sku' => "TTV-{$colour}-{$size}", 'quantity' => $qty,
                    'absolute_price' => 1000 + $qty * 100, 'image' => $colour === 'Red' ? 'catalog/__tt-stock-test.png' : null, 'status' => 1,
                    'sort_order' => $qty, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('product_option_combination_values')->insert([
                    ['combination_id' => $comboId, 'product_option_value_id' => $povIds[$colour]],
                    ['combination_id' => $comboId, 'product_option_value_id' => $povIds[$size]],
                ]);
                $qty++;
            }
        }

        return ['product_id' => $productId, 'ovIds' => $ovIds];
    }

    public function test_the_cron_pushes_every_combination_its_own_quantity(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Stock group', 'tiktok_category_id' => '900001']);
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
        $group = TikTokProductGroup::create(['name' => 'Legacy group', 'tiktok_category_id' => '900001']);
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

    public function test_the_group_page_pushes_stock_through_the_same_resolver(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Page group', 'tiktok_category_id' => '900001']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-3', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => json_encode(['TTV-Red-S' => 's1', 'TTV-Red-M' => 's2', 'TTV-Black-S' => 's3', 'TTV-Black-M' => 's4']),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.product-groups.pushStock', $group->id), ['ids' => [$productId]])
            ->assertSessionHas('status');

        $calls = $this->callsFor('POST', '/product/202309/products/tt-3/inventory/update');
        $this->assertCount(1, $calls);
        $byId = collect($calls[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['inventory'][0]['quantity']);
        $this->assertSame(['s1' => 1, 's2' => 2, 's3' => 3, 's4' => 4], $byId->all());
    }

    public function test_a_push_names_every_axis_on_every_sku_and_stores_the_map_by_seller_sku(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Push group', 'tiktok_category_id' => '900001', 'markup_percent' => 0]);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending',
        ]);
        $this->callResponses['POST /product/202309/products'] = function (array $body) {
            $skus = [];
            foreach ($body['skus'] as $i => $sku) {
                $skus[] = ['id' => 'new-' . ($i + 1), 'seller_sku' => $sku['seller_sku']];
            }

            return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['product_id' => 'tt-new', 'skus' => $skus]]];
        };

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.product-groups.push', $group->id), ['ids' => [$productId]]);

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $skus = $creates[0]['body']['skus'];
        $this->assertCount(4, $skus);
        $this->assertSame('TTV-Red-S', $skus[0]['seller_sku']);
        $this->assertSame(
            [['name' => 'Colour', 'value_name' => 'Red'], ['name' => 'Size', 'value_name' => 'S']],
            $skus[0]['sales_attributes']
        );
        $this->assertSame('1', $skus[0]['inventory'][0]['quantity'] === 1 ? '1' : (string) $skus[0]['inventory'][0]['quantity']);
        $this->assertSame('1100', $skus[0]['price']['amount']);
        $this->assertArrayHasKey('sku_img', $skus[0], 'Red carries its own picture.');
        $this->assertArrayNotHasKey('sku_img', $skus[2], 'Black has none.');

        $pivot = TikTokProductGroupProduct::where('product_id', $productId)->first();
        $this->assertSame('tt-new', $pivot->tiktok_product_id);
        $this->assertSame(
            ['TTV-Red-S' => 'new-1', 'TTV-Red-M' => 'new-2', 'TTV-Black-S' => 'new-3', 'TTV-Black-M' => 'new-4'],
            json_decode($pivot->tiktok_sku_id, true)
        );
    }

    public function test_the_price_cron_prices_by_the_listing_rule_then_the_group(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Price group', 'tiktok_category_id' => '900001', 'markup_percent' => 10]);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-9', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => json_encode(['TTV-Red-S' => 's1', 'TTV-Red-M' => 's2', 'TTV-Black-S' => 's3', 'TTV-Black-M' => 's4']),
        ]);

        $this->artisan('tiktok:push-price')->assertExitCode(0);

        $calls = $this->callsFor('POST', '/product/202309/products/tt-9/prices/update');
        $this->assertCount(1, $calls);
        $byId = collect($calls[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['price']['amount']);
        $this->assertSame(['s1' => '1210', 's2' => '1320', 's3' => '1430', 's4' => '1540'], $byId->all(), 'the group\'s 10% on each combination');
        $this->assertSame([], $this->callsFor('POST', '/product/202309/products/tt-9/inventory/update'), 'the price row never sends stock');

        (new \Extensions\tiktok\Models\TikTokListing())->forceFill(['product_id' => $productId, 'markup_percent' => 0, 'markup_fixed' => 50])->save();
        $this->artisan('tiktok:push-price')->assertExitCode(0);
        $calls = $this->callsFor('POST', '/product/202309/products/tt-9/prices/update');
        $this->assertCount(2, $calls);
        $byId = collect($calls[1]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['price']['amount']);
        $this->assertSame(['s1' => '1150', 's2' => '1250', 's3' => '1350', 's4' => '1450'], $byId->all());
    }

    public function test_the_price_cron_starts_a_simple_product_from_the_listings_own_price(): void
    {
        $pfx = (string) config('catalog.prefix');
        $simple = DB::table($pfx . 'product')->insertGetId([
            'model' => 'TTS-1', 'sku' => 'TTS', 'quantity' => 4, 'price' => 300,
            'status' => 1, 'image' => 'catalog/__tt-stock-test.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        ['product_id' => $varied] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Own price group', 'tiktok_category_id' => '900001']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $simple,
            'tiktok_product_id' => 'tt-s', 'tiktok_sku_id' => 'single-1', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
        ]);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $varied,
            'tiktok_product_id' => 'tt-9', 'tiktok_sku_id' => json_encode(self::SKU_IDS), 'sync_status' => 'pushed', 'last_pushed_at' => now(),
        ]);
        (new \Extensions\tiktok\Models\TikTokListing())->forceFill(['product_id' => $simple, 'price' => 500, 'markup_percent' => 10])->save();
        (new \Extensions\tiktok\Models\TikTokListing())->forceFill(['product_id' => $varied, 'price' => 5, 'markup_percent' => 10])->save();

        $this->artisan('tiktok:push-price')->assertExitCode(0);

        $calls = $this->callsFor('POST', '/product/202309/products/tt-s/prices/update');
        $this->assertCount(1, $calls);
        $this->assertSame([['id' => 'single-1', 'price' => ['amount' => '550', 'currency' => 'PHP']]], $calls[0]['body']['skus']);

        $calls = $this->callsFor('POST', '/product/202309/products/tt-9/prices/update');
        $byId = collect($calls[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['price']['amount']);
        $this->assertSame(['s1' => '1210', 's2' => '1320', 's3' => '1430', 's4' => '1540'], $byId->all(), 'each variation from the catalog, the stored Price ignored');
    }

    public function test_a_groups_rule_is_the_same_sum_as_a_listings(): void
    {
        foreach ([[7.5, null, 123.45], [-10, -5, 1000.0], [null, 50, 99.99], [12.345, 0.555, 10.0]] as [$pct, $fixed, $base]) {
            $group = (new TikTokProductGroup())->forceFill(['markup_percent' => $pct, 'markup_fixed' => $fixed]);
            $listing = (new \Extensions\tiktok\Models\TikTokListing())->forceFill(['markup_percent' => $pct, 'markup_fixed' => $fixed]);
            $this->assertSame($listing->priceFor($base), $group->applyMarkup($base), "percent {$pct}, fixed {$fixed} on {$base}");
        }
    }

    public function test_another_stores_group_never_reaches_this_store(): void
    {
        $main = TikTokSetting::query()->orderBy('id')->first();
        $outlet = TikTokSetting::create([
            'mode' => 'production', 'store_name' => 'Outlet', 'app_key' => 'k2', 'app_secret' => 's2',
            'access_token' => 't2', 'refresh_token' => 'r2', 'shop_cipher' => 'c2', 'expires_at' => now()->addDays(3),
        ]);
        $pid = 424242;
        $other = TikTokProductGroup::create(['tiktok_setting_id' => $outlet->id, 'name' => 'Outlet group', 'tiktok_category_id' => '900001', 'markup_percent' => 20]);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $other->id, 'product_id' => $pid, 'sync_status' => 'pending']);

        $this->app->instance('tiktok.route-store', $main);
        $rule = \Extensions\tiktok\Commands\TikTokPushPrice::priceRule([(object) ['product_id' => $pid]]);
        $this->assertSame(1000.0, $rule(1000.0, (object) ['product_id' => $pid]), 'no group on this store: the catalog price');
        $this->app->forgetInstance('tiktok.route-store');

        $this->assertSame([], app(\Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::class)->forProducts([$pid]),
            'with no store bound, the lookup reads the default store\'s groups, not every store\'s');
        $this->assertSame((int) $main->id, (int) TikTokSetting::defaultStore()->id);
    }

    private const SKU_IDS = ['TTV-Red-S' => 's1', 'TTV-Red-M' => 's2', 'TTV-Black-S' => 's3', 'TTV-Black-M' => 's4'];

    private function categoryWithSpecification(): void
    {
        \Extensions\tiktok\Models\TikTokCategoryTemplate::query()->create([
            'category_id' => '900001', 'fetched_at' => now(),
            'attributes' => [['id' => '100089', 'name' => 'Specification', 'type' => 'SALES_PROPERTY', 'values' => []]],
        ]);
    }

    private function liveItem(string $ttId, array $colour, ?array $size, array $skuIds = self::SKU_IDS): array
    {
        $skus = [];
        foreach ($skuIds as $seller => $id) {
            [, $c, $s] = explode('-', $seller);
            $attributes = [['id' => $colour[0], 'name' => $colour[1], 'value_id' => $colour[2][$c][0], 'value_name' => $colour[2][$c][1]]];
            if ($size !== null) {
                $attributes[] = ['id' => $size[0], 'name' => $size[1], 'value_id' => $size[2][$s][0], 'value_name' => $size[2][$s][1]];
            }
            $skus[] = ['id' => $id, 'seller_sku' => $seller, 'price' => ['sale_price' => '1000'], 'inventory' => [['quantity' => 1]], 'sales_attributes' => $attributes];
        }

        return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['id' => $ttId, 'status' => 'ACTIVATE', 'title' => 'Two axis', 'skus' => $skus]]];
    }

    private function linkedInGroup(int $productId, string $ttId, array $map = self::SKU_IDS): TikTokProductGroupProduct
    {
        $group = TikTokProductGroup::create(['name' => 'Rename group ' . $ttId, 'tiktok_category_id' => '900001']);

        return TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => $ttId, 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => json_encode($map),
        ]);
    }

    private function updateThroughGroup(TikTokProductGroupProduct $pivot): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager())
            ->post(route('ext.tiktok.product-groups.updateProduct', $pivot->tiktok_product_group_id), ['ids' => [$pivot->product_id]]);
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
        $this->assertSame([['name' => 'Colour', 'value_name' => 'Red'], ['name' => 'Size', 'value_name' => 'S']], $skus['TTV-Red-S']['sales_attributes']);
        $this->assertSame([['name' => 'Colour', 'value_name' => 'Black'], ['name' => 'Size', 'value_name' => 'M']], $skus['TTV-Black-M']['sales_attributes']);
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
        $this->assertSame(['name' => 'Colour', 'value_name' => 'Red'], $skus['TTV-Red-S']['sales_attributes'][0], 'the seller\'s own type is renamed');
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
            ['7301', 'Colour', ['Red' => ['8301', 'Red'], 'Black' => ['8302', 'Black']]],
            ['7302', 'Size', ['S' => ['9001', 'S'], 'M' => ['9002', 'M']]]);

        $this->updateThroughGroup($pivot);

        $skus = $this->editedSkus('tt-r3');
        $this->assertSame([['id' => '7301', 'value_id' => '8301', 'value_name' => 'Red'], ['id' => '7302', 'value_id' => '9001', 'value_name' => 'S']], $skus['TTV-Red-S']['sales_attributes']);
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
            ['7401', 'Colour', ['Red' => ['8401', 'Red'], 'Black' => ['8402', 'Black']]],
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
        $this->assertSame(self::SKU_IDS, json_decode((string) $pivot->fresh()->tiktok_sku_id, true));
        $this->assertSame(self::SKU_IDS, json_decode((string) \Extensions\tiktok\Models\TikTokListing::where('product_id', $productId)->value('tiktok_sku_id'), true));

        $this->artisan('tiktok:push-stock')->assertExitCode(0);
        $stock = $this->callsFor('POST', '/product/202309/products/tt-r4/inventory/update');
        $this->assertCount(1, $stock);
        $this->assertSame(['s1' => 1, 's2' => 2, 's3' => 3, 's4' => 4], collect($stock[0]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['inventory'][0]['quantity'])->all());
    }

    public function test_the_listing_page_update_renames_the_same_way(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        \Extensions\tiktok\Models\TikTokListing::create([
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
        $this->assertSame([['name' => 'Colour', 'value_name' => 'Black'], ['name' => 'Size', 'value_name' => 'S']], $skus['TTV-Black-S']['sales_attributes']);
        $this->assertSame(self::SKU_IDS, json_decode((string) \Extensions\tiktok\Models\TikTokListing::where('product_id', $productId)->value('tiktok_sku_id'), true));
    }

    public function test_a_rename_that_cannot_be_paired_is_skipped_and_the_update_goes_through(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        \Extensions\tiktok\Models\TikTokListing::create([
            'product_id' => $productId, 'tiktok_product_id' => 'tt-l2', 'tiktok_category_id' => '900001',
            'tiktok_sku_id' => json_encode(self::SKU_IDS),
        ]);
        $this->callResponses['GET /product/202309/products/tt-l2'] = $this->liveItem('tt-l2',
            ['7301', 'Shade', ['Red' => ['8301', 'Crimson'], 'Black' => ['8302', 'Onyx']]], null,
            ['TTV-Red-S' => 's1', 'TTV-Black-S' => 's3']);
        $warning = 'Variation names were not changed: the item on TikTok Shop has 1 variation type (Shade) and the product has 2 (Colour, Size), and 2 new variations were not added.';

        $this->actingAs($this->manager())->post(route('ext.tiktok.listings.push_update', $productId))
            ->assertSessionHas('warning', 'Update pushed. ' . $warning);

        $skus = $this->editedSkus('tt-l2');
        $this->assertSame(['TTV-Red-S' => 's1', 'TTV-Black-S' => 's3'], array_map(fn ($s) => $s['id'] ?? null, $skus));
        $this->assertSame([['id' => '7301', 'value_id' => '8301', 'value_name' => 'Crimson']], $skus['TTV-Red-S']['sales_attributes'], 'kept as TikTok holds it');
        $this->assertSame([['id' => '7301', 'value_id' => '8302', 'value_name' => 'Onyx']], $skus['TTV-Black-S']['sales_attributes']);
        $this->assertNull(\Extensions\tiktok\Models\TikTokListing::where('product_id', $productId)->value('last_push_error'), 'the update succeeded, so the row carries no error');
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
        $this->assertSame([['id' => '7301', 'value_id' => '8301', 'value_name' => 'Crimson'], ['id' => '7302', 'value_id' => '9001', 'value_name' => 'S']], $second['TTV-Red-S']['sales_attributes']);
        $this->assertSame('pushed', $pivot->fresh()->sync_status);
        $response->assertSessionHas('warning');
        $summary = (string) session('warning');
        $this->assertStringStartsWith('Update: 1 updated. #' . $productId . ': Variation names were not changed: TikTok Shop refused them (', $summary);
        $this->assertStringContainsString('sale property is invalid', $summary);
        $this->assertNull(\Extensions\tiktok\Models\TikTokListing::where('product_id', $productId)->value('last_push_error'), 'the update succeeded, so the row carries no error');
        $this->assertSame(self::SKU_IDS, json_decode((string) $pivot->fresh()->tiktok_sku_id, true));
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
        $this->assertSame('error', $pivot->fresh()->sync_status);
        $this->assertSame(
            'TikTok Shop did not say which variations this item has (TikTok Shop did not answer: Internal error), so nothing was sent.',
            \Extensions\tiktok\Models\TikTokListing::where('product_id', $productId)->value('last_push_error')
        );
    }

    public function test_a_new_item_is_created_as_before_without_reading_the_shop(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $this->categoryWithSpecification();
        $group = TikTokProductGroup::create(['name' => 'Create group', 'tiktok_category_id' => '900001']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending']);
        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-c1', 'skus' => array_map(fn ($seller, $id) => ['id' => $id, 'seller_sku' => $seller], array_keys(self::SKU_IDS), self::SKU_IDS)]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.product-groups.push', $group->id), ['ids' => [$productId]]);

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        foreach ($creates[0]['body']['skus'] as $sku) {
            $this->assertArrayNotHasKey('id', $sku);
            $this->assertSame(['Colour', 'Size'], array_column($sku['sales_attributes'], 'name'));
        }
        $this->assertSame([], array_filter($this->sentCalls, fn ($c) => $c['method'] === 'GET' && str_starts_with($c['path'], '/product/202309/products/')));
        $this->assertSame(self::SKU_IDS, json_decode((string) TikTokProductGroupProduct::where('product_id', $productId)->value('tiktok_sku_id'), true));
    }

    public function test_the_heal_keeps_a_sku_map_longer_than_255_characters_on_the_group_pivot(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Wide group', 'tiktok_category_id' => '900001']);
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
