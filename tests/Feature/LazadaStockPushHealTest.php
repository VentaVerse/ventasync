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

/**
 * Phase F parity (#184): Lazada's stock and price pushes get the same
 * guarantees Shopee's and TikTok's have - skips named never counted
 * anonymously, orphan variants never zeroed, stale sku_ids healed with
 * one item read, honest listing verdicts instead of the synthetic
 * PARTIAL_FAILURE sentence, and a failed batch never flashing green.
 */
class LazadaStockPushHealTest extends TestCase
{
    use RefreshDatabase;

    public array $getResponses = [];

    /** @var array<int, array{path: string, params: array}> */
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

    /** The item/get answer, so the staleness heal has something to read. */
    private function fakeItem(array $skuIds): void
    {
        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 900,
            'skus' => array_map(fn ($sku, $id) => ['SkuId' => $id, 'SellerSku' => $sku, 'ShopSku' => $sku . '-shop', 'Status' => 'active', 'quantity' => 1, 'price' => 100], array_keys($skuIds), array_values($skuIds)),
        ]]];
    }

    private function updateCalls(): array
    {
        return array_values(array_filter($this->sentPosts, fn ($c) => $c['path'] === '/product/price_quantity/update'));
    }

    /** The anonymous "Success: 2, Error: 1" is dead: the missing SKU is named. */
    public function test_a_variation_missing_from_lazada_is_named(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5, 'LZ-2' => 8]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        // The staleness heal asks Lazada; it answers with the same single
        // SKU, so LZ-2 genuinely is not on Lazada.
        $this->fakeItem(['LZ-1' => 11]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.sync_quantity', $listing->product_id))
            ->assertRedirect();

        $flash = (string) session('status');
        $this->assertStringContainsString('LZ-2 (not on Lazada)', $flash, 'the skip is named, never an anonymous count');

        $calls = $this->updateCalls();
        $this->assertCount(1, $calls, 'only the variant Lazada has is sent');
        $this->assertStringContainsString('<SkuId>11</SkuId>', (string) $calls[0]['params']['payload']);

        // The listing verdict is honest - no synthetic PARTIAL_FAILURE.
        $this->assertTrue((bool) $listing->fresh()->last_sync_ok);
        $this->assertNull($listing->fresh()->last_sync_error_code);
    }

    /** An orphan variant is skipped by name - never pushed a zero. */
    public function test_an_orphan_variant_is_skipped_not_zeroed(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-GONE', 'sku_id' => 12]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.sync_quantity', $listing->product_id))
            ->assertRedirect();

        $flash = (string) session('status');
        $this->assertStringContainsString('LZ-GONE (on Lazada but not in the catalogue)', $flash);

        $calls = $this->updateCalls();
        $this->assertCount(1, $calls);
        $this->assertStringNotContainsString('<SkuId>12</SkuId>', (string) $calls[0]['params']['payload'],
            'the orphan is never zeroed');
    }

    /** A refusal naming the sku heals once and retries once. */
    public function test_a_sku_refusal_heals_and_retries_once(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->fakeItem(['LZ-1' => 77]); // Lazada's real id differs from the stale cache

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

    /** A failed push cannot flash green, and the listing wears the real reason. */
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

    /** The listing page says how much of the catalogue stands on Lazada, and names the verb. */
    public function test_the_listing_page_carries_coverage_and_points_at_update(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5, 'LZ-2' => 8]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-1', 'sku_id' => 11]);
        $this->fakeItem(['LZ-1' => 11]);

        $page = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk();

        $page->assertSee('1 of 2 variations');
        $page->assertSee('LZ-2');
        $page->assertSee('Update on Lazada - the update carries every catalogue variation');
    }

    /** A combination added in the catalogue triggers the staleness refetch at last. */
    public function test_a_new_combination_makes_the_cache_refetch(): void
    {
        $pid = $this->comboProduct(['LZ-1' => 5, 'LZ-NEW' => 3]);
        $listing = LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '900']);
        // The cache knows only LZ-1; the diff used to read POV alone, so a
        // combination-only product never refetched.
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
}
