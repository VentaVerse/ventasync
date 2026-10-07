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

class ProductDeleteReachesChannelsTest extends TestCase
{
    use RefreshDatabase;

    public array $calls = [];

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
                $this->test->calls[] = 'shopee ' . $path;

                return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'message' => '']];
            }
        });
        $this->app->instance(LazadaClient::class, new class($test) extends LazadaClient {
            public function __construct(private $test) {}
            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $this->test->calls[] = 'lazada ' . $apiPath;

                return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
            }
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $this->test->calls[] = 'lazada ' . $apiPath;

                return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
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
                $this->test->calls[] = 'tiktok ' . $path;

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

    public function test_a_delete_forgets_the_product_on_every_channel_and_calls_no_marketplace(): void
    {
        $pid = $this->seedProduct('Everywhere', 'EV-1');
        $this->listEverywhere($pid, 'EV-1');

        $r = $this->actingAs($this->manager())->delete(route('products.destroy', $pid));
        $r->assertRedirect(route('products.index'));
        $flash = (string) session('status');
        $this->assertSame('Deleted. It stays on Shopee (Main store), Lazada (PH store) and TikTok Shop (TT store); remove it there when you want it gone.', $flash);

        $this->assertFalse($this->productExists($pid));
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $pid)->count());
        $this->assertSame(0, ShopeeListing::query()->where('product_id', $pid)->count());
        $this->assertSame(0, ShopeeProductGroupProduct::query()->where('product_id', $pid)->count());
        $this->assertSame(0, LazadaProduct::query()->where('product_id', $pid)->count());
        $this->assertSame(0, TikTokListing::query()->where('product_id', $pid)->count());
        $this->assertSame([], $this->calls, 'no marketplace was called');

        $this->actingAs($this->manager())->withSession(['status' => $flash])->get(route('products.index'))->assertOk()->assertSee('remove it there when you want it gone');
    }

    public function test_a_disconnected_or_refusing_store_cannot_block_a_delete(): void
    {
        ShopeeSetting::query()->update(['access_token' => '', 'refresh_token' => '']);
        $pid = $this->seedProduct('Blocked before', 'BL-1');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 222, 'sku' => 'BL-1', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);

        $this->actingAs($this->manager())->delete(route('products.destroy', $pid))->assertRedirect(route('products.index'));

        $this->assertFalse($this->productExists($pid));
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $pid)->count());
        $this->assertSame([], $this->calls);
        $this->assertSame('Deleted. It stays on Shopee (Main store); remove it there when you want it gone.', (string) session('status'));
    }

    public function test_an_item_the_mirror_says_is_gone_is_not_named_as_staying(): void
    {
        $pid = $this->seedProduct('Gone already', 'GA-1');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 333, 'sku' => 'GA-1', 'live_status' => 'SELLER_DELETE', 'live_checked_at' => now()]);

        $this->actingAs($this->manager())->delete(route('products.destroy', $pid))->assertRedirect(route('products.index'));

        $this->assertFalse($this->productExists($pid));
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $pid)->count());
        $this->assertSame('Deleted.', (string) session('status'));
    }

    public function test_the_confirm_sheet_says_where_the_product_stays(): void
    {
        $pid = $this->seedProduct('Sits everywhere', 'SE-1');
        $this->listEverywhere($pid, 'SE-1');
        $alone = $this->seedProduct('Sits nowhere', 'SN-1');

        $note = 'It stays on Shopee (Main store), Lazada (PH store) and TikTok Shop (TT store); only VentaSync&#039;s record of it is removed.';
        $index = $this->actingAs($this->manager())->get(route('products.index'))->assertOk();
        $index->assertSee('Delete Sits everywhere? This cannot be undone. ' . $note, false);
        $index->assertSee('Delete Sits nowhere? This cannot be undone."', false);

        $this->actingAs($this->manager())->get(route('products.edit', $pid))->assertOk()
            ->assertSee('Delete Sits everywhere? This cannot be undone. ' . $note, false);
        $this->actingAs($this->manager())->get(route('products.edit', $alone))->assertOk()
            ->assertSee('Delete Sits nowhere? This cannot be undone."', false);
    }

    public function test_a_bulk_delete_forgets_each_product(): void
    {
        $a = $this->seedProduct('Bulk A', 'BA-1');
        $this->listEverywhere($a, 'BA-1');
        $b = $this->seedProduct('Bulk B', 'BB-1');

        $r = $this->actingAs($this->manager())->post(route('products.bulk'), ['action' => 'delete', 'ids' => [$a, $b]]);
        $r->assertRedirect(route('products.index'));
        $this->assertSame('Deleted 2 products. They stay on Shopee (Main store), Lazada (PH store) and TikTok Shop (TT store); remove them there when you want them gone.', (string) session('status'));
        $this->assertFalse($this->productExists($a));
        $this->assertFalse($this->productExists($b));
        $this->assertSame([], $this->calls);
    }

    public function test_the_orphan_queues_are_gone_from_the_listings_pages(): void
    {
        ShopeeProductLink::create(['product_id' => 999001, 'shopee_item_id' => 999001, 'sku' => 'GONE', 'live_status' => 'NORMAL']);
        LazadaProduct::query()->create(['product_id' => 999002, 'lazada_item_id' => 'LZ-GONE', 'live_status' => 'active']);

        $group = UserGroup::create(['name' => 'Orphan queue viewer']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/product', 'view_lazada/product'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['store' => ShopeeSetting::query()->value('id')]))->assertOk()
            ->assertDontSee('deleted catalog product')->assertDontSee('999001');
        $this->actingAs($user)->get(route('ext.lazada.products.index', ['store' => LazadaSetting::query()->value('id')]))->assertOk()
            ->assertDontSee('deleted catalog product')->assertDontSee('LZ-GONE');
    }

    public function test_the_sweep_names_stranded_rows_and_forgets_them_on_apply(): void
    {
        ShopeeProductLink::create(['product_id' => 424242, 'shopee_item_id' => 999001, 'sku' => 'STR', 'live_status' => 'NORMAL']);
        TikTokListing::create(['product_id' => 424242, 'tiktok_product_id' => 'tt-gone', 'tiktok_category_id' => '900009']);

        $this->artisan('catalog:purge-stranded-listings')
            ->expectsOutputToContain('Deleted product #424242: still held on Shopee (Main store) item 999001, live; TikTok Shop (TT store) item tt-gone')
            ->expectsOutputToContain('the marketplace items stay where they are')
            ->assertExitCode(0);
        $this->assertSame(1, ShopeeProductLink::query()->where('product_id', 424242)->count());

        $this->artisan('catalog:purge-stranded-listings --apply')->assertExitCode(0);
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', 424242)->count());
        $this->assertSame(0, TikTokListing::query()->where('product_id', 424242)->count());
        $this->assertSame([], $this->calls);
    }

    private function furnishedProduct(string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = (int) DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100, 'status' => 1, 'image' => 'catalog/x.png',
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Furnished ' . $sku, 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        $po = (int) DB::table($pfx . 'product_option')->insertGetId(['product_id' => $pid, 'option_id' => 1, 'value' => '', 'required' => 0]);
        foreach ([$sku . '-red', $sku . '-blue'] as $i => $vsku) {
            DB::table($pfx . 'product_option_value')->insert([
                'product_id' => $pid, 'product_option_id' => $po, 'option_id' => 1, 'option_value_id' => $i + 1,
                'sku' => $vsku, 'quantity' => 2, 'subtract' => 1, 'price' => 0, 'price_prefix' => '+',
                'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
            ]);
        }
        $combo = (int) DB::table('product_option_combinations')->insertGetId([
            'product_id' => $pid, 'sku' => $sku . '-combo', 'quantity' => 1, 'absolute_price' => 120, 'image' => null, 'status' => 1,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_option_combination_values')->insert(['combination_id' => $combo, 'product_option_value_id' => (int) DB::table($pfx . 'product_option_value')->where('product_id', $pid)->value('product_option_value_id')]);
        DB::table($pfx . 'product_image')->insert(['product_id' => $pid, 'image' => 'catalog/x-2.png', 'sort_order' => 1]);
        if (\Illuminate\Support\Facades\Schema::hasTable($pfx . 'product_special')) {
            DB::table($pfx . 'product_special')->insert(['product_id' => $pid, 'customer_group_id' => 1, 'priority' => 1, 'price' => 90, 'date_start' => '2026-01-01', 'date_end' => '2026-12-31']);
        }

        return $pid;
    }

    public function test_a_delete_takes_the_variations_images_and_specials_with_it_and_a_stranded_row_holds_no_sku(): void
    {
        $pfx = (string) config('catalog.prefix');
        $pid = $this->furnishedProduct('SX');

        app(\App\Services\Catalog\ProductDeleter::class)->delete([$pid]);

        $this->assertSame(0, DB::table($pfx . 'product_option')->where('product_id', $pid)->count());
        $this->assertSame(0, DB::table($pfx . 'product_option_value')->where('product_id', $pid)->count());
        $this->assertSame(0, DB::table('product_option_combinations')->where('product_id', $pid)->count());
        $this->assertSame(0, DB::table('product_option_combination_values')->count());
        $this->assertSame(0, DB::table($pfx . 'product_image')->where('product_id', $pid)->count());

        DB::table($pfx . 'product_option_value')->insert([
            'product_id' => 999999, 'product_option_id' => 1, 'option_id' => 1, 'option_value_id' => 1,
            'sku' => 'GHOST-1', 'quantity' => 2, 'subtract' => 1, 'price' => 0, 'price_prefix' => '+',
            'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
        ]);
        DB::table('product_option_combinations')->insert([
            'product_id' => 999999, 'sku' => 'GHOST-2', 'quantity' => 1, 'absolute_price' => 120, 'image' => null, 'status' => 1,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $failures = [];
        foreach (['GHOST-1', 'GHOST-2'] as $sku) {
            (new \App\Rules\UniqueSku())->validate('sku', $sku, function ($m) use (&$failures) { $failures[] = $m; });
        }
        $this->assertSame([], $failures, 'a variation of a product that no longer exists holds no SKU');

        $alive = $this->furnishedProduct('LIVE');
        (new \App\Rules\UniqueSku())->validate('sku', 'LIVE-red', function ($m) use (&$failures) { $failures[] = $m; });
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('already used by a variation of "Furnished LIVE"', $failures[0]);

        $this->artisan('catalog:purge-orphan-rows')->expectsOutputToContain('#999999')->assertExitCode(0);
        $this->assertSame(1, DB::table($pfx . 'product_option_value')->where('product_id', 999999)->count());
        $this->artisan('catalog:purge-orphan-rows', ['--apply' => true])->expectsOutputToContain('Removed the rows of 1 deleted product(s).')->assertExitCode(0);
        $this->assertSame(0, DB::table($pfx . 'product_option_value')->where('product_id', 999999)->count());
        $this->assertSame(0, DB::table('product_option_combinations')->where('product_id', 999999)->count());
        $this->assertSame(2, DB::table($pfx . 'product_option_value')->where('product_id', $alive)->count());
        $this->artisan('catalog:purge-orphan-rows')->expectsOutputToContain('No orphan rows')->assertExitCode(0);
    }
}
