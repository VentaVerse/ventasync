<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductGroupProduct;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A catalog delete reaches the channels (the owner, 2026-09-05). The user
 * deleted the product for a reason: it goes from every store that holds
 * it, marketplace first, local rows after, and a store that refuses
 * keeps the catalog row too. The orphan queues that used to collect the
 * leftovers are gone; the sweep command handles what older deletes left.
 */
class ProductDeleteReachesChannelsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{channel:string, call:string}> */
    public array $calls = [];

    /** What Shopee answers to delete_item. */
    public array $shopeeAnswer = ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'message' => '']];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada', 'tiktok'] as $ext) {
            $manager->install($ext);
            $manager->enable($ext);
        }
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app->register(\Extensions\tiktok\TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create([
            'store_name' => 'Main store', 'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);
        LazadaSetting::query()->create([
            'store_name' => 'PH store', 'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't', 'refresh_token' => 'r',
        ]);
        TikTokSetting::create([
            'store_name' => 'TT store', 'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3),
        ]);

        $test = $this;
        $this->app->instance(ShopeeClient::class, new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->calls[] = ['channel' => 'shopee', 'call' => $path . ' ' . json_encode($body)];

                return $this->test->shopeeAnswer;
            }
        });
        $this->app->instance(LazadaClient::class, new class($test) extends LazadaClient {
            public function __construct(private $test) {}
            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $this->test->calls[] = ['channel' => 'lazada', 'call' => $apiPath . ' ' . ($params['seller_sku_list'] ?? $params['sku_id_list'] ?? '')];

                return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
            }
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                return ['ok' => false, 'status' => 500, 'body' => ['code' => '500', 'message' => 'unexpected GET ' . $apiPath]];
            }
            public function sign(string $apiPath, array $params, string $appSecret): string
            {
                return 'test-sign';
            }
        });
        $this->app->instance(TikTokClient::class, new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function delete(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            {
                $this->test->calls[] = ['channel' => 'tiktok', 'call' => $path . ' ' . json_encode($body)];

                return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => []]];
            }
        });
    }

    private int $groupSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Delete reach group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', ['view_catalog/product', 'manage_catalog/product'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now()->subDays(30), 'date_modified' => now()->subDays(30), 'date_available' => now()->subDays(30),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    /** On every channel at once, the way a real product is. */
    private function listEverywhere(int $pid, string $sku): void
    {
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 111, 'sku' => $sku, 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        ShopeeListing::create(['product_id' => $pid, 'shopee_category_id' => 100]);
        $grp = ShopeeProductGroup::create(['name' => 'Delete reach ' . $pid]);
        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $grp->id, 'product_id' => $pid, 'shopee_item_id' => 111, 'sync_status' => 'pushed']);
        LazadaProduct::query()->create(['product_id' => $pid, 'lazada_item_id' => 'LZ1', 'live_status' => 'active']);
        TikTokListing::create(['product_id' => $pid, 'tiktok_product_id' => 'tt-1', 'tiktok_category_id' => '900009', 'live_status' => 'ACTIVATE']);
    }

    private function productExists(int $pid): bool
    {
        return DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $pid)->exists();
    }

    public function test_the_three_channels_answer_the_contract(): void
    {
        $removers = app(IntegrationRegistry::class)->productRemovers();
        $this->assertCount(3, $removers);
        foreach ($removers as $r) {
            $this->assertInstanceOf(\App\Integrations\Contracts\ProductRemover::class, $r);
        }
    }

    public function test_a_delete_removes_the_product_from_every_store_first_and_then_the_catalog(): void
    {
        $pid = $this->seedProduct('Everywhere', 'EV-1');
        $this->listEverywhere($pid, 'EV-1');

        $r = $this->actingAs($this->manager())->delete(route('products.destroy', $pid));
        $r->assertRedirect(route('products.index'));
        $flash = (string) session('status');
        $this->assertStringStartsWith('Deleted. Also removed from ', $flash);
        $this->assertStringContainsString('Shopee (Main store) item 111', $flash);
        $this->assertStringContainsString('Lazada (PH store) item LZ1', $flash);
        $this->assertStringContainsString('TikTok Shop (TT store) product tt-1', $flash);

        $this->assertFalse($this->productExists($pid));
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $pid)->count());
        $this->assertSame(0, ShopeeListing::query()->where('product_id', $pid)->count());
        $this->assertSame(0, ShopeeProductGroupProduct::query()->where('product_id', $pid)->count());
        $this->assertSame(0, LazadaProduct::query()->where('product_id', $pid)->count());
        $this->assertSame(0, TikTokListing::query()->where('product_id', $pid)->count());

        $calls = array_map(fn ($c) => $c['channel'] . ' ' . $c['call'], $this->calls);
        $this->assertContains('shopee /api/v2/product/delete_item {"item_id":111}', $calls);
        $this->assertContains('lazada /product/remove ["EV-1"]', $calls);
        $this->assertContains('tiktok /product/202309/products {"product_ids":["tt-1"]}', $calls);
    }

    public function test_a_store_that_refuses_keeps_the_catalog_row_and_its_own_rows(): void
    {
        $pid = $this->seedProduct('Refused', 'RF-1');
        $this->listEverywhere($pid, 'RF-1');
        $this->shopeeAnswer = ['ok' => true, 'status' => 200, 'body' => ['error' => 'error_auth', 'message' => 'Invalid access_token.']];

        $r = $this->actingAs($this->manager())->from(route('products.edit', $pid))->delete(route('products.destroy', $pid));
        $r->assertRedirect(route('products.edit', $pid));
        $flash = (string) session('error');
        $this->assertStringContainsString('Not deleted.', $flash);
        $this->assertStringContainsString('Refused: Shopee (Main store): ', $flash);
        $this->assertStringContainsString('Nothing of it was removed from the catalog. Fix that, then delete again.', $flash);

        // The catalog row and Shopee's rows stay for the retry.
        $this->assertTrue($this->productExists($pid));
        $this->assertSame(1, ShopeeProductLink::query()->where('product_id', $pid)->count());
        $this->assertSame(1, ShopeeListing::query()->where('product_id', $pid)->count());
        // The stores that said yes are done; a retry asks only Shopee.
        $this->assertSame(0, LazadaProduct::query()->where('product_id', $pid)->count());
        $this->assertSame(0, TikTokListing::query()->where('product_id', $pid)->count());
    }

    public function test_an_item_already_gone_at_the_marketplace_is_cleaned_without_a_call(): void
    {
        $pid = $this->seedProduct('Gone', 'GN-1');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 222, 'sku' => 'GN-1', 'live_status' => 'SHOPEE_DELETE', 'live_checked_at' => now()]);

        $this->actingAs($this->manager())->delete(route('products.destroy', $pid))->assertRedirect(route('products.index'));

        $this->assertFalse($this->productExists($pid));
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $pid)->count());
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c['channel'] === 'shopee'));
        $this->assertSame('Deleted.', (string) session('status'));
    }

    public function test_the_confirm_sheet_says_where_the_product_sits(): void
    {
        $pid = $this->seedProduct('Sits everywhere', 'SE-1');
        $this->listEverywhere($pid, 'SE-1');
        $alone = $this->seedProduct('Sits nowhere', 'SN-1');

        $note = 'Also deleted from Shopee (Main store), Lazada (PH store) and TikTok Shop (TT store).';
        $index = $this->actingAs($this->manager())->get(route('products.index'))->assertOk();
        $index->assertSee('Delete Sits everywhere? This cannot be undone. ' . $note);
        $index->assertSee('Delete Sits nowhere? This cannot be undone."', false);

        $this->actingAs($this->manager())->get(route('products.edit', $pid))->assertOk()
            ->assertSee('Delete Sits everywhere? This cannot be undone. ' . $note);
        $this->actingAs($this->manager())->get(route('products.edit', $alone))->assertOk()
            ->assertSee('Delete Sits nowhere? This cannot be undone."', false);
    }

    public function test_a_bulk_delete_reaches_the_channels_per_product(): void
    {
        $a = $this->seedProduct('Bulk A', 'BA-1');
        $this->listEverywhere($a, 'BA-1');
        $b = $this->seedProduct('Bulk B', 'BB-1');

        $r = $this->actingAs($this->manager())->post(route('products.bulk'), ['action' => 'delete', 'ids' => [$a, $b]]);
        $r->assertRedirect(route('products.index'));
        $this->assertStringStartsWith('Deleted 2 products. Also removed from Shopee (Main store) item 111', (string) session('status'));
        $this->assertFalse($this->productExists($a));
        $this->assertFalse($this->productExists($b));
    }

    public function test_the_orphan_queues_are_gone_from_the_listings_pages(): void
    {
        ShopeeProductLink::create(['product_id' => 424242, 'shopee_item_id' => 999001, 'sku' => 'GONE-1', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        LazadaProduct::query()->create(['product_id' => 424243, 'lazada_item_id' => 'LZ-GONE']);

        $group = UserGroup::create(['name' => 'Queue watchers']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product', 'view_lazada/product', 'manage_lazada/product'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        // Explicit stores: one URL default cannot serve two multi-store channels.
        $this->actingAs($user)->get(route('ext.shopee.products.index', ['store' => ShopeeSetting::query()->value('id')]))->assertOk()
            ->assertDontSee('deleted catalog product')->assertDontSee('999001');
        $this->actingAs($user)->get(route('ext.lazada.products.index', ['store' => LazadaSetting::query()->value('id')]))->assertOk()
            ->assertDontSee('deleted catalog product')->assertDontSee('LZ-GONE');
    }

    public function test_the_sweep_names_stranded_rows_and_removes_them_on_apply(): void
    {
        ShopeeProductLink::create(['product_id' => 424242, 'shopee_item_id' => 999001, 'sku' => 'GONE-1', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        TikTokListing::create(['product_id' => 424242, 'tiktok_product_id' => 'tt-gone']);

        // One expectation per line: a line satisfies only the first
        // substring it matches, so two substrings of one line never both pass.
        $this->artisan('catalog:purge-stranded-listings')
            ->expectsOutputToContain('Deleted product #424242: still held on Shopee (Main store) item 999001, live; TikTok Shop (TT store) item tt-gone')
            ->expectsOutputToContain('1 stranded. Run again with --apply')
            ->assertExitCode(0);
        $this->assertSame(1, ShopeeProductLink::query()->where('product_id', 424242)->count());

        $this->artisan('catalog:purge-stranded-listings', ['--apply' => true])
            ->expectsOutputToContain('1 purged, 0 kept for a retry.')
            ->assertExitCode(0);
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', 424242)->count());
        $this->assertSame(0, TikTokListing::query()->where('product_id', 424242)->count());
        $calls = array_map(fn ($c) => $c['channel'] . ' ' . $c['call'], $this->calls);
        $this->assertContains('shopee /api/v2/product/delete_item {"item_id":999001}', $calls);
        $this->assertContains('tiktok /product/202309/products {"product_ids":["tt-gone"]}', $calls);
    }
}
