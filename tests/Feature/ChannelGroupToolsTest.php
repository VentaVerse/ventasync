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

    public function test_lazada_check_links_a_product_the_shop_already_holds_and_drops_a_lost_link(): void
    {
        $unlisted = $this->seedProduct('GT-NEW');
        $lost = $this->seedProduct('GT-LOST');
        $group = LazadaProductGroup::create(['name' => 'Lazada tools group', 'lazada_category_id' => 10001]);
        $newListing = LazadaListing::create(['product_id' => $unlisted, 'primary_category_id' => 10001]);
        LazadaProductGroupProduct::create(['lazada_product_group_id' => $group->id, 'product_id' => $unlisted, 'lazada_listing_id' => $newListing->id, 'sync_status' => 'pending']);
        $lostListing = LazadaListing::create(['product_id' => $lost, 'primary_category_id' => 10001, 'lazada_item_id' => '9990001']);
        LazadaProductGroupProduct::create(['lazada_product_group_id' => $group->id, 'product_id' => $lost, 'lazada_listing_id' => $lostListing->id, 'sync_status' => 'pushed']);

        $this->lazadaAnswers['GET /products/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['products' => [
            ['item_id' => 5550001, 'status' => 'active', 'skus' => [['SellerSku' => 'GT-NEW']]],
        ]]]];
        $this->lazadaAnswers['GET /product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '207', 'message' => 'E207: SKU not exist']];

        $r = $this->actingAs($this->manager(['manage_lazada/product_group', 'view_lazada/product_group']))
            ->post(route('ext.lazada.product-groups.check', ['store' => $this->lazadaStore->id, 'id' => $group->id]));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('1 linked to the item Lazada holds', session('error'));
        $this->assertStringContainsString('1 no longer on Lazada', session('error'));
        $this->assertSame('5550001', (string) LazadaListing::where('product_id', $unlisted)->value('lazada_item_id'));
        $this->assertNotNull(LazadaListing::where('product_id', $lost)->value('lazada_deleted_at'));
        $this->assertSame('pushed', LazadaProductGroupProduct::where('product_id', $unlisted)->value('sync_status'));
        $this->assertSame('error', LazadaProductGroupProduct::where('product_id', $lost)->value('sync_status'));
    }

    public function test_lazada_check_scoped_to_a_selection_asks_only_about_those_products(): void
    {
        $chosen = $this->seedProduct('GT-PICK');
        $other = $this->seedProduct('GT-REST');
        $group = LazadaProductGroup::create(['name' => 'Lazada scoped group', 'lazada_category_id' => 10001]);
        foreach ([$chosen, $other] as $pid) {
            $listing = LazadaListing::create(['product_id' => $pid, 'primary_category_id' => 10001, 'lazada_item_id' => '777' . $pid]);
            LazadaProductGroupProduct::create(['lazada_product_group_id' => $group->id, 'product_id' => $pid, 'lazada_listing_id' => $listing->id, 'sync_status' => 'pushed']);
        }

        $asked = [];
        $this->lazadaAnswers['GET /products/get'] = function (array $params) use (&$asked, $chosen) {
            $asked = array_merge($asked, json_decode((string) ($params['sku_seller_list'] ?? '[]'), true) ?: []);

            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['products' => [
                ['item_id' => (int) ('777' . $chosen), 'status' => 'active', 'skus' => [['SellerSku' => 'GT-PICK']]],
            ]]]];
        };

        $manager = $this->manager(['manage_lazada/product_group', 'view_lazada/product_group']);
        $this->actingAs($manager)->post(route('ext.lazada.product-groups.check', ['store' => $this->lazadaStore->id, 'id' => $group->id]), ['ids' => [$chosen]]);

        $this->assertContains('GT-PICK', $asked);
        $this->assertNotContains('GT-REST', $asked, 'the unselected product is not asked about');
        $this->assertNull(LazadaListing::where('product_id', $other)->value('lazada_deleted_at'), 'and is not judged either');

        $html = $this->actingAs($manager)->get(route('ext.lazada.product-groups.products', ['store' => $this->lazadaStore->id, 'id' => $group->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Link IDs', $html);
        $this->assertGreaterThanOrEqual(1, substr_count($html, route('ext.lazada.product-groups.check', ['store' => $this->lazadaStore->id, 'id' => $group->id])));
    }

    public function test_lazada_unlinked_view_lists_what_the_shop_holds_that_nothing_points_at(): void
    {
        $known = $this->seedProduct('GT-KNOWN');
        LazadaListing::create(['product_id' => $known, 'primary_category_id' => 10001, 'lazada_item_id' => '5550001']);
        $group = LazadaProductGroup::create(['name' => 'Lazada orphans group', 'lazada_category_id' => 10001]);
        $this->lazadaAnswers['GET /products/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['products' => [
            ['item_id' => 5550001, 'skus' => [['SellerSku' => 'GT-KNOWN']]],
            ['item_id' => 5550002, 'skus' => [['SellerSku' => 'ORPHAN-1'], ['SellerSku' => 'ORPHAN-2']]],
        ]]]];

        $r = $this->actingAs($this->manager(['view_lazada/product_group']))
            ->get(route('ext.lazada.product-groups.orphans', ['store' => $this->lazadaStore->id, 'id' => $group->id]));

        $r->assertOk();
        $r->assertSee('5550002');
        $r->assertSee('ORPHAN-1, ORPHAN-2');
        $r->assertDontSee('GT-KNOWN');
    }

    public function test_tiktok_check_links_by_seller_sku_and_drops_a_lost_link(): void
    {
        $unlisted = $this->seedProduct('TT-NEW');
        $lost = $this->seedProduct('TT-LOST');
        $group = TikTokProductGroup::create(['name' => 'TikTok tools group', 'tiktok_category_id' => '900001']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $unlisted, 'sync_status' => 'pending']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $lost, 'tiktok_product_id' => 'tt-lost', 'sync_status' => 'pushed']);
        TikTokListing::create(['product_id' => $lost, 'tiktok_product_id' => 'tt-lost']);

        $this->tiktokAnswers['POST /product/202309/products/search'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['products' => [
            ['id' => 'tt-held', 'status' => 'ACTIVATE', 'title' => 'Held on TikTok', 'skus' => [['seller_sku' => 'TT-NEW']]],
        ]]]];

        $r = $this->actingAs($this->manager(['manage_tiktok/product_group', 'view_tiktok/product_group']))
            ->post(route('ext.tiktok.product-groups.check', $group->id));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('1 linked to the product TikTok Shop holds', session('error'));
        $this->assertStringContainsString('1 no longer on TikTok Shop', session('error'));
        $this->assertSame('tt-held', TikTokListing::where('product_id', $unlisted)->value('tiktok_product_id'));
        $this->assertSame('tt-held', TikTokProductGroupProduct::where('product_id', $unlisted)->value('tiktok_product_id'));
        $this->assertNull(TikTokListing::where('product_id', $lost)->value('tiktok_product_id'));
        $this->assertSame('error', TikTokProductGroupProduct::where('product_id', $lost)->value('sync_status'));
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

    public function test_tiktok_unlinked_view_lists_the_shops_unknown_products(): void
    {
        $known = $this->seedProduct('TT-KNOWN');
        TikTokListing::create(['product_id' => $known, 'tiktok_product_id' => 'tt-known']);
        $group = TikTokProductGroup::create(['name' => 'TikTok orphans group', 'tiktok_category_id' => '900001']);
        $this->tiktokAnswers['POST /product/202309/products/search'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['products' => [
            ['id' => 'tt-known', 'status' => 'ACTIVATE', 'title' => 'Known', 'skus' => [['seller_sku' => 'TT-KNOWN']]],
            ['id' => 'tt-stray', 'status' => 'ACTIVATE', 'title' => 'Made in the seller center', 'skus' => [['seller_sku' => 'STRAY-1']]],
        ]]]];

        $r = $this->actingAs($this->manager(['view_tiktok/product_group']))
            ->get(route('ext.tiktok.product-groups.orphans', $group->id));

        $r->assertOk();
        $r->assertSee('tt-stray');
        $r->assertSee('Made in the seller center');
        $r->assertSee('STRAY-1');
        $r->assertDontSee('tt-known');
    }
}
