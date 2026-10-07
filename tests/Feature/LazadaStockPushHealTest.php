<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LazadaStockPushHealTest extends TestCase
{
    use RefreshDatabase;

    public array $getResponses = [];

    public array $sentPosts = [];

    public array $postResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        LazadaSetting::query()->create([
            'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r',
        ]);

        $test = $this;
        $fake = new class($test) extends LazadaClient {
            public function __construct(private $test) {}
            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $this->test->sentPosts[] = ['path' => $apiPath, 'params' => $params];
                $answer = $this->test->postResponses[$apiPath] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($params);
                }

                return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
            }
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $answer = $this->test->getResponses[$apiPath] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($params);
                }

                return $answer
                    ?? ['ok' => false, 'status' => 500, 'body' => ['code' => '500', 'message' => 'unexpected GET ' . $apiPath]];
            }
            public function sign(string $apiPath, array $params, string $appSecret): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(LazadaClient::class, $fake);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'LZ push group ' . uniqid()]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada/product', 'manage_lazada/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function comboProduct(array $skuQty): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LZ', 'sku' => 'LZ', 'quantity' => 99, 'price' => 100,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'LZ push product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        $i = 0;
        foreach ($skuQty as $sku => $qty) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $pid, 'sku' => $sku, 'quantity' => $qty,
                'absolute_price' => 100 + $qty, 'image' => null, 'status' => 1,
                'sort_order' => $i++, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $pid;
    }

    private function fakeItem(array $skuIds): void
    {
        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 900,
            'skus' => array_map(fn ($sku, $id) => ['SkuId' => $id, 'SellerSku' => $sku, 'ShopSku' => $sku . '-shop', 'Status' => 'active', 'quantity' => 1, 'price' => 100], array_keys($skuIds), array_values($skuIds)),
        ]]];
    }

    private function states(): \Extensions\lazada\Services\Lazada\LazadaListingStates
    {
        return app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class);
    }

    private function line(int $pid): ?string
    {
        return $this->states()->errors([$pid])[$pid] ?? null;
    }

    private function named(int $pid, string $name): int
    {
        DB::table((string) config('catalog.prefix') . 'product_description')->where('product_id', $pid)->update(['name' => $name]);

        return $pid;
    }

    private function groupManager(): User
    {
        $group = UserGroup::create(['name' => 'LZ group page ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', [
            'view_lazada/product', 'manage_lazada/product', 'view_lazada/product_group', 'manage_lazada/product_group',
        ])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function fakeShop(array $itemSkus): void
    {
        $products = [];
        foreach ($itemSkus as $itemId => $skus) {
            $products[] = ['item_id' => $itemId, 'status' => 'active', 'skus' => array_map(fn ($s) => ['SellerSku' => $s], $skus)];
        }
        $this->getResponses['/products/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['products' => $products]]];
    }

    public function test_a_failure_then_a_success_on_another_path_clears_the_line(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->fakeItem(['LZ-1' => 11]);

        $this->actingAs($this->manager())->post(route('ext.lazada.products.upload', $pid))->assertRedirect();
        $this->assertNotNull($this->line($pid));
        $this->assertSame([$pid], $this->states()->erroredProductIds());

        $this->actingAs($this->manager())->post(route('ext.lazada.products.sync_quantity', $pid))->assertRedirect();
        $this->assertNull($this->line($pid));
        $this->assertSame([], $this->states()->erroredProductIds());
    }

    public function test_unlink_clears_the_line(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        $listing->forceFill(['last_sync_ok' => false, 'last_sync_error_message' => 'Quota exceeded', 'last_synced_at' => now()])->save();
        $this->states()->recordOutcome($pid, 'Price push: LZ-1: Price is too low');
        $this->assertNotNull($this->line($pid));

        $this->actingAs($this->manager())->post(route('ext.lazada.products.unlink', $pid))->assertRedirect();

        $this->assertNull($this->line($pid));
        $fresh = $listing->fresh();
        $this->assertNull($fresh->last_push_error);
        $this->assertTrue((bool) $fresh->last_sync_ok);
    }

    public function test_the_error_filter_lists_a_missing_variation_and_drops_a_cleared_error(): void
    {
        $storeId = (int) LazadaSetting::defaultStore()->id;

        $missing = $this->named($this->comboProduct(['LZ-1' => 5, 'LZ-2' => 8]), 'Missing size product');
        $a = LazadaProduct::create(['product_id' => $missing, 'lazada_item_id' => '900', 'live_status' => 'active']);
        \App\Integrations\Listings\ListingVariations::remember('lazada', $storeId, $missing, ['LZ-1']);

        $refused = $this->named($this->comboProduct(['LZB-1' => 3]), 'Refused price product');
        $b = LazadaProduct::create(['product_id' => $refused, 'lazada_item_id' => '901', 'live_status' => 'active']);
        $this->states()->recordOutcome($refused, 'Price push: LZB-1: Price is too low');

        $clean = $this->named($this->comboProduct(['LZC-1' => 3]), 'Clean quiet product');
        $c = LazadaProduct::create(['product_id' => $clean, 'lazada_item_id' => '902', 'live_status' => 'active']);

        $group = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Filter group', 'lazada_category_id' => 4321]);
        foreach ([$a, $b, $c] as $row) {
            \Extensions\lazada\Models\LazadaProductGroupProduct::create([
                'lazada_product_group_id' => $group->id, 'product_id' => $row->product_id,
                'lazada_product_id' => $row->id, 'sync_status' => 'pushed',
            ]);
        }
        $user = $this->groupManager();

        $this->assertSame([$missing, $refused], $this->states()->erroredProductIds());
        foreach ([
            route('ext.lazada.products.index', ['sync_status' => 'error']) => 'lsm-flag is-on',
            route('ext.lazada.product-groups.products', [$group->id, 'sync_status' => 'error']) => '>Has an error</option>',
        ] as $url => $says) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Missing size product')->assertSee('Refused price product')->assertDontSee('Clean quiet product')
                ->assertSee($says, false);
        }

        $this->states()->clearErrors([$refused]);

        $this->assertSame([$missing], $this->states()->erroredProductIds());
        foreach ([route('ext.lazada.products.index', ['sync_status' => 'error']), route('ext.lazada.product-groups.products', [$group->id, 'sync_status' => 'error'])] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Missing size product')->assertDontSee('Refused price product')->assertDontSee('Clean quiet product');
        }
    }

    public function test_a_stored_push_error_survives_a_link_check_and_a_refresh_then_a_stock_push_clears_it(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->states()->recordOutcome($pid, 'Price push: LZ-1: Price is too low');

        $this->fakeShop(['900' => ['LZ-1']]);
        $this->actingAs($this->manager())->post(route('ext.lazada.products.check'), ['product_ids' => [$pid]])->assertRedirect();
        $this->assertStringContainsString('1 confirmed on Lazada', (string) session('status'));
        $this->assertStringContainsString('Price is too low', (string) $this->line($pid));

        $this->fakeItem(['LZ-1' => 11]);
        app(\Extensions\lazada\Services\Lazada\LazadaItemCache::class)
            ->refreshListing($listing->fresh(), LazadaSetting::defaultStore(), app(LazadaClient::class));
        $this->assertStringContainsString('Price is too low', (string) $this->line($pid));

        $this->actingAs($this->manager())->post(route('ext.lazada.products.sync_quantity', $pid))->assertRedirect();
        $this->assertNull($this->line($pid));
    }

    public function test_a_link_check_that_finds_the_item_taken_sets_the_line_and_adopts_nothing(): void
    {
        $holder = $this->comboProduct(['LZ-1' => 5]);
        LazadaProduct::create(['product_id' => $holder, 'lazada_item_id' => '900']);
        $other = $this->comboProduct(['LZX-1' => 2]);
        $row = LazadaProduct::create(['product_id' => $other]);

        $this->fakeShop(['900' => ['LZX-1']]);
        $this->actingAs($this->manager())->post(route('ext.lazada.products.check'), ['product_ids' => [$other]])->assertRedirect();

        $this->assertNull($row->fresh()->lazada_item_id, 'an item another product holds is never adopted');
        $this->assertStringContainsString('linked to catalog product #' . $holder, (string) $this->line($other));
        $this->assertNotNull(session('error'));
    }

    public function test_a_group_stock_failure_shows_on_the_line_and_its_success_clears_it(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->fakeItem(['LZ-1' => 11]);
        $group = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Stock group', 'lazada_category_id' => 4321]);
        $pivot = \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'product_id' => $pid,
            'lazada_product_id' => $listing->id, 'sync_status' => 'pushed',
        ]);

        $this->postResponses['/product/price_quantity/update'] = ['ok' => true, 'status' => 200,
            'body' => ['code' => '901', 'message' => 'Quota exceeded for this API']];
        $this->actingAs($this->groupManager())->post(route('ext.lazada.product-groups.pushStock', $group->id), ['ids' => [$pid]])->assertRedirect();
        $this->assertStringContainsString('Stock push:', (string) $this->line($pid));
        $this->assertStringContainsString('Quota exceeded', (string) $this->line($pid));
        $this->assertSame('error', $pivot->fresh()->sync_status);

        unset($this->postResponses['/product/price_quantity/update']);
        $this->actingAs($this->groupManager())->post(route('ext.lazada.product-groups.pushStock', $group->id), ['ids' => [$pid]])->assertRedirect();
        $this->assertNull($this->line($pid));
        $this->assertSame('pushed', $pivot->fresh()->sync_status);
        $this->assertNull($pivot->fresh()->push_error);
    }

    private function updateCalls(): array
    {
        return array_values(array_filter($this->sentPosts, fn ($c) => $c['path'] === '/product/price_quantity/update'));
    }

    public function test_a_variation_missing_from_lazada_is_left_alone_and_the_rest_go_up(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5, 'LZ-2' => 8]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->fakeItem(['LZ-1' => 11]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.sync_quantity', $listing->product_id))
            ->assertRedirect();

        $this->assertNull(session('error'));
        $this->assertCount(1, $this->updateCalls(), 'the variant Lazada has goes up');
        $this->assertNull($listing->fresh()->last_sync_error_code);
    }

    public function test_an_orphan_variant_is_left_alone(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-GONE', 'sku_id' => 12]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.sync_quantity', $listing->product_id))
            ->assertRedirect();

        $this->assertNull(session('error'));
        $this->assertCount(1, $this->updateCalls(), 'only the twin the catalogue has');
    }

    public function test_a_variation_switched_off_for_the_store_is_not_synced(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5, 'LZ-2' => 8]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-2', 'sku_id' => 12]);
        \App\Integrations\Listings\ListingVariations::save('lazada', (int) ($listing->lazada_setting_id ?? 0), $pid, ['LZ-1']);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.sync_quantity', $listing->product_id))
            ->assertRedirect();

        $this->assertNull(session('error'));
        $this->assertCount(1, $this->updateCalls(), 'LZ-2 is off for this store');
    }

    public function test_a_sku_refusal_heals_and_retries_once(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->fakeItem(['LZ-1' => 77]);

        $attempt = 0;
        $this->postResponses['/product/price_quantity/update'] = function () use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                return ['ok' => true, 'status' => 200, 'body' => ['code' => 'E0207', 'message' => 'The sku not exist']];
            }

            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
        };

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.sync_quantity', $listing->product_id))
            ->assertRedirect();

        $this->assertStringContainsString('repaired mid-push', (string) session('status'));
        $calls = $this->updateCalls();
        $this->assertCount(2, $calls, 'one refusal, one retry - never a loop');
        $this->assertStringContainsString('<SkuId>77</SkuId>', (string) $calls[1]['params']['payload'],
            'the retry carries the fresh sku_id');
        $this->assertSame(77, (int) LazadaProductVariant::query()->where('lazada_product_id', $listing->id)->value('sku_id'));
    }

    public function test_a_failure_is_honest_in_tone_and_in_the_columns(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->postResponses['/product/price_quantity/update'] = ['ok' => true, 'status' => 200,
            'body' => ['code' => '901', 'message' => 'Quota exceeded for this API']];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.sync_quantity', $listing->product_id))
            ->assertRedirect();

        $this->assertNull(session('status'), 'a failure must not wear the green tick');
        $this->assertStringContainsString('Quota exceeded', (string) session('error'));

        $fresh = $listing->fresh();
        $this->assertFalse((bool) $fresh->last_sync_ok);
        $this->assertStringContainsString('LZ-1: Quota exceeded', (string) $fresh->last_sync_error_message,
            'the verdict names the SKU and the reason - not PARTIAL_FAILURE with a count');
    }

    public function test_the_listing_page_carries_coverage_and_points_at_update(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5, 'LZ-2' => 8]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->fakeItem(['LZ-1' => 11]);

        $page = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk();

        $page->assertDontSee('1 of 2 variations');
        $page->assertDontSee('carries every catalogue variation, missing ones included');
    }

    public function test_a_new_combination_makes_the_cache_refetch(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5, 'LZ-NEW' => 3]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->fakeItem(['LZ-1' => 11, 'LZ-NEW' => 12]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.sync_quantity', $listing->product_id))
            ->assertRedirect();

        $calls = $this->updateCalls();
        $this->assertCount(2, $calls, 'both variants push once the refetch found the new twin');
        $payloads = implode('', array_map(fn ($c) => (string) $c['params']['payload'], $calls));
        $this->assertStringContainsString('<SkuId>12</SkuId>', $payloads);
    }

    public function test_the_price_cron_prices_by_the_listing_rule_then_the_group_then_the_catalog(): void
    {
        $a = $this->comboProduct(['LA-1' => 5]);
        $la = LazadaProduct::create(['product_id' => $a, 'lazada_item_id' => '901', 'markup_percent' => 10]);
        LazadaProductVariant::create(['lazada_product_id' => $la->id, 'seller_sku' => 'LA-1', 'sku_id' => 11]);

        $b = $this->comboProduct(['LB-1' => 5]);
        $lb = LazadaProduct::create(['product_id' => $b, 'lazada_item_id' => '902']);
        LazadaProductVariant::create(['lazada_product_id' => $lb->id, 'seller_sku' => 'LB-1', 'sku_id' => 12]);
        $group = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Grouped', 'lazada_category_id' => 4321, 'markup_percent' => 20]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create(['lazada_product_group_id' => $group->id, 'product_id' => $b, 'lazada_product_id' => $lb->id]);

        $c = $this->comboProduct(['LC-1' => 5]);
        $lc = LazadaProduct::create(['product_id' => $c, 'lazada_item_id' => '903']);
        LazadaProductVariant::create(['lazada_product_id' => $lc->id, 'seller_sku' => 'LC-1', 'sku_id' => 13]);

        $this->artisan('lazada:push-price')->assertExitCode(0);

        $calls = $this->updateCalls();
        $this->assertCount(3, $calls);
        $payloads = implode(' ', array_map(fn ($c) => (string) $c['params']['payload'], $calls));
        $this->assertMatchesRegularExpression('~<SkuId>11</SkuId>.*?115\.50~s', $payloads, 'the listing\'s 10%');
        $this->assertMatchesRegularExpression('~<SkuId>12</SkuId>.*?126\.00~s', $payloads, 'the group\'s 20%');
        $this->assertMatchesRegularExpression('~<SkuId>13</SkuId>.*?105\.00~s', $payloads, 'the catalog price as it is');
        $this->assertStringNotContainsString('<Quantity>', $payloads, 'the price row never sends stock');
        $this->assertSame('sync_price', $la->fresh()->last_sync_action);
    }
}
