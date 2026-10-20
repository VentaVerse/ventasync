<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaProductGroup;
use Extensions\Lazada\Models\LazadaProductGroupProduct;
use Extensions\Lazada\Models\LazadaStore;
use Extensions\Lazada\Services\Lazada\LazadaClient;
use Extensions\TikTok\Models\TikTokListing;
use Extensions\TikTok\Models\TikTokProductGroup;
use Extensions\TikTok\Models\TikTokProductGroupProduct;
use Extensions\TikTok\Models\TikTokStore;
use Extensions\TikTok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChannelGroupToolsTest extends TestCase
{
    use RefreshDatabase;

    private array $sentCalls = [];
    public array $lazadaAnswers = [];
    public array $tiktokAnswers = [];
    private int $groupSeq = 0;

    private LazadaStore $lazadaStore;
    private TikTokStore $tiktokStore;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['lazada', 'tiktok'] as $ext) {
            $manager->install($ext);
            $manager->enable($ext);
        }
        $this->app->register(\Extensions\Lazada\LazadaExtension::class);
        $this->app->register(\Extensions\TikTok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');

        $this->lazadaStore = LazadaStore::query()->create(['mode' => 'live', 'region' => 'ph', 'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't', 'refresh_token' => 'r']);
        $this->tiktokStore = TikTokStore::create(['mode' => 'production', 'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3)]);

        $test = $this;
        $lz = new class($test) extends LazadaClient {
            public function __construct(private $test) {}
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            { return $this->test->answerLazada('GET', $apiPath, $params); }
            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            { return $this->test->answerLazada('POST', $apiPath, $params); }
            public function sign(string $apiPath, array $params, string $appSecret): string { return 'sign'; }
        };
        $this->app->instance(LazadaClient::class, $lz);
        $tt = new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function get(string $appKey, string $appSecret, string $accessToken, string $path, array $extraParams = [], ?string $shopCipher = null): array
            { return $this->test->answerTiktok('GET', $path, $extraParams); }
            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            { return $this->test->answerTiktok('POST', $path, $body); }
        };
        $this->app->instance(TikTokClient::class, $tt);
    }

    public function answerLazada(string $method, string $path, array $params): array
    {
        $this->sentCalls[] = ['channel' => 'lazada', 'method' => $method, 'path' => $path, 'params' => $params];
        $answer = $this->lazadaAnswers[$method . ' ' . $path] ?? null;

        return $answer instanceof \Closure ? $answer($params) : ($answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['products' => []]]]);
    }

    public function answerTiktok(string $method, string $path, array $body): array
    {
        $this->sentCalls[] = ['channel' => 'tiktok', 'method' => $method, 'path' => $path, 'body' => $body];
        $answer = $this->tiktokAnswers[$method . ' ' . $path] ?? null;

        return $answer instanceof \Closure ? $answer($body) : ($answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['products' => []]]]);
    }

    private function manager(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Group tools ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $sku): int
    {
        $langId = (int) config('catalog.default_language_id');
        $productId = DB::table('products')->insertGetId([
            'sku' => $sku, 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'brand_id' => 0, 'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Tools product ' . $sku, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_the_lazada_workbench_check_needs_no_group(): void
    {
        $unlisted = $this->seedProduct('WB-NEW');
        $lost = $this->seedProduct('WB-LOST');
        $newListing = LazadaListing::create(['product_id' => $unlisted, 'primary_category_id' => 10001]);
        $lostListing = LazadaListing::create(['product_id' => $lost, 'primary_category_id' => 10001, 'lazada_item_id' => '9990001']);

        $this->lazadaAnswers['GET /products/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['products' => [
            ['item_id' => 5550009, 'status' => 'active', 'skus' => [['SellerSku' => 'WB-NEW']]],
        ]]]];
        $this->lazadaAnswers['GET /product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '207', 'message' => 'E207: SKU not exist']];

        $r = $this->actingAs($this->manager(['manage_lazada/product', 'view_lazada/product']))
            ->post(route('ext.lazada.products.check', ['store' => $this->lazadaStore->id]), ['product_ids' => [$newListing->product_id, $lostListing->product_id]]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('1 linked to the item Lazada holds', session('error'));
        $this->assertStringContainsString('1 no longer on Lazada', session('error'));
        $this->assertSame('5550009', (string) LazadaListing::where('product_id', $unlisted)->value('lazada_item_id'));
        $this->assertNotNull(LazadaListing::where('product_id', $lost)->value('lazada_deleted_at'));
    }

    public function test_a_lazada_sku_search_that_misses_keeps_the_link_while_the_listing_number_is_on_lazada(): void
    {
        $kept = $this->seedProduct('WB-KEPT');
        $listing = LazadaListing::create(['product_id' => $kept, 'primary_category_id' => 10001, 'lazada_item_id' => '9990002']);
        $this->lazadaAnswers['GET /product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['item_id' => 9990002, 'status' => 'active', 'skus' => []]]];

        $r = $this->actingAs($this->manager(['manage_lazada/product', 'view_lazada/product']))
            ->post(route('ext.lazada.products.check', ['store' => $this->lazadaStore->id]), ['product_ids' => [$listing->product_id]]);

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 confirmed on Lazada', session('status'));
        $this->assertNull($listing->fresh()->lazada_deleted_at, 'an empty SKU search alone never marks a listing gone');
        $this->assertSame('9990002', (string) $listing->fresh()->lazada_item_id);
    }

    public function test_the_lazada_workbench_offers_the_check_in_head_and_bulk_bar(): void
    {
        $this->seedProduct('WB-BTN');
        $html = $this->actingAs($this->manager(['manage_lazada/product', 'view_lazada/product']))
            ->get(route('ext.lazada.products.index', ['store' => $this->lazadaStore->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Link IDs', $html);
        $this->assertStringContainsString('Refresh from Lazada', $html);
    }

    public function test_the_tiktok_workbench_check_needs_no_group(): void
    {
        $unlisted = $this->seedProduct('WBT-NEW');
        $lost = $this->seedProduct('WBT-LOST');
        TikTokListing::create(['product_id' => $lost, 'tiktok_product_id' => 'tt-gone']);

        $this->tiktokAnswers['POST /product/202309/products/search'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['products' => [
            ['id' => 'tt-held', 'status' => 'ACTIVATE', 'title' => 'Held on TikTok', 'skus' => [['seller_sku' => 'WBT-NEW']]],
        ]]]];

        $r = $this->actingAs($this->manager(['manage_tiktok/product', 'view_tiktok/product']))
            ->post(route('ext.tiktok.products.check'), ['product_ids' => [$unlisted, $lost]]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('1 linked to the product TikTok Shop holds', session('error'));
        $this->assertStringContainsString('1 no longer on TikTok Shop', session('error'));
        $this->assertSame('tt-held', TikTokListing::where('product_id', $unlisted)->value('tiktok_product_id'));
        $this->assertNull(TikTokListing::where('product_id', $lost)->value('tiktok_product_id'));
    }

    public function test_the_tiktok_workbench_offers_the_check_in_head_and_bulk_bar(): void
    {
        $this->seedProduct('WBT-BTN');
        $html = $this->actingAs($this->manager(['manage_tiktok/product', 'view_tiktok/product']))
            ->get(route('ext.tiktok.products.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Link IDs', $html);
        $this->assertStringContainsString('Refresh from TikTok Shop', $html);
    }
}
