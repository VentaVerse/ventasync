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

/**
 * TikTok stock and variations on one resolver (parity 2026-08-26). The
 * cron and the group page used to read product_option_value alone, one
 * SKU per option value of the FIRST axis, so a two-axis product went up
 * as half of itself and every stock tick wrote the wrong figure: the
 * same drift that oversold on Lazada. Combinations come first now, the
 * pivot map is keyed by seller SKU, and a legacy map keyed by
 * option_value_id still resolves until the next push rewrites it.
 */
class TiktokStockPushTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{method: string, path: string, body: array}> */
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

        // A real picture on disk: the push refuses a product without one.
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
            Permission::whereIn('key', ['manage_tiktok/product_group', 'view_tiktok/product_group'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Colour x Size: four combinations with their own SKUs and quantities,
     * and one option value row per axis value (the single-axis table).
     * @return array{product_id: int, ovIds: array<string, int>}
     */
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

    /**
     * A push from before the seller-sku map (keyed by the Colour axis's
     * option_value_ids) is re-read from the shop and rebuilt by seller SKU
     * before anything is sent; every combination then pushes its own
     * figure. It used to push the axis rows' 99s through the legacy keys,
     * which the all-or-nothing rule (2026-09-07) no longer allows: a map
     * that cannot prove the sets match is repaired first.
     */
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

    /** Push Price is its own automation (2026-09-07): the listing's rule, else the group's, else the catalog price. */
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

        // The listing's own rule wins over the group's.
        (new \Extensions\tiktok\Models\TikTokListing())->forceFill(['product_id' => $productId, 'markup_percent' => 0, 'markup_fixed' => 50])->save();
        $this->artisan('tiktok:push-price')->assertExitCode(0);
        $calls = $this->callsFor('POST', '/product/202309/products/tt-9/prices/update');
        $this->assertCount(2, $calls);
        $byId = collect($calls[1]['body']['skus'])->keyBy('id')->map(fn ($s) => $s['price']['amount']);
        $this->assertSame(['s1' => '1150', 's2' => '1250', 's3' => '1350', 's4' => '1450'], $byId->all());
    }

    /**
     * Live, 2026-09-07: "Data too long for column 'tiktok_sku_id'" stopped
     * every TikTok stock push. The map repair writes every seller SKU TikTok
     * holds (nineteen-digit ids), and the group pivot's column was a
     * varchar(255) while the listing's was TEXT. The pivot keeps the whole
     * map now, however many SKUs the shop holds.
     */
    public function test_the_heal_keeps_a_sku_map_longer_than_255_characters_on_the_group_pivot(): void
    {
        ['product_id' => $productId] = $this->seedTwoAxisProduct();
        $group = TikTokProductGroup::create(['name' => 'Wide group', 'tiktok_category_id' => '900001']);
        $pivot = TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-7', 'sync_status' => 'pushed', 'last_pushed_at' => now(),
            'tiktok_sku_id' => null, // an empty map: the push reads the product first
        ]);
        // The shop holds the four catalog SKUs and eight more the catalog never had.
        $skus = [];
        foreach (['TTV-Red-S', 'TTV-Red-M', 'TTV-Black-S', 'TTV-Black-M', 'TTV-Red-L', 'TTV-Red-XL', 'TTV-Black-L', 'TTV-Black-XL', 'TTV-Blue-S', 'TTV-Blue-M', 'TTV-Blue-L', 'TTV-Blue-XL'] as $i => $sellerSku) {
            $skus[] = ['id' => '17290000000000' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT), 'seller_sku' => $sellerSku, 'price' => ['sale_price' => '1000'], 'inventory' => [['quantity' => 1]]];
        }
        $this->callResponses['GET /product/202309/products/tt-7'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['id' => 'tt-7', 'status' => 'ACTIVATE', 'title' => 'Wide', 'skus' => $skus]]];

        // The map is repaired and kept whole; the eight SKUs the catalog
        // does not have are left alone and the four it does go up.
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
