<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase E of the channels rebuild (#184, card #190): the stock and price
 * pushes build from the LINK ROWS, skip what Shopee no longer has BY
 * NAME, parse the per-model failure_list Shopee has been sending all
 * along, and heal a model-level refusal by re-reading the models and
 * retrying once. Nothing is skipped silently, nothing is retried
 * silently, and a failed model can no longer be recorded as a success.
 */
class ShopeeStockPushHealTest extends TestCase
{
    use RefreshDatabase;

    /** POST answers by path; closures receive the body. */
    public array $postResponses = [];

    /** GET answers by path. */
    public array $getResponses = [];

    /** @var array<int, array{path: string, body: array}> */
    public array $sentPosts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create(['mode' => 'live', 'partner_id' => '1', 'partner_key' => 'k', 'shop_id' => '2', 'access_token' => 't', 'refresh_token' => 'r']);

        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->sentPosts[] = ['path' => $path, 'body' => $body];
                $answer = $this->test->postResponses[$path] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($body);
                }

                return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['response' => []]];
            }
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                $answer = $this->test->getResponses[$path] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($extraQuery);
                }

                return $answer ?? ['ok' => false, 'status' => 500, 'body' => ['error' => 'error', 'message' => 'unexpected GET ' . $path]];
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);
    }

    private int $seq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Shopee push group ' . (++$this->seq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** A product with three variation SKUs in product_option_value. */
    private function productWithVariations(array $skuQty): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => 'PAR', 'sku' => 'PAR', 'quantity' => 99, 'price' => 100,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Pushable product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        foreach ($skuQty as $sku => $qty) {
            DB::table($pfx . 'product_option_value')->insert([
                'product_id' => $pid, 'product_option_id' => 1, 'option_id' => 1, 'option_value_id' => 1,
                'sku' => $sku, 'quantity' => $qty, 'subtract' => 1, 'price' => 0, 'price_prefix' => '+',
                'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
            ]);
        }

        return $pid;
    }

    private function stockCalls(): array
    {
        return array_values(array_filter($this->sentPosts, fn ($c) => $c['path'] === '/api/v2/product/update_stock'));
    }

    /** The deleted-1pc incident: what Shopee no longer has is skipped BY NAME, never silently. */
    public function test_a_variation_without_a_link_is_skipped_and_named(): void
    {
        $pid = $this->productWithVariations(['PRD-1PC' => 5, 'PRD-5PC' => 6, 'PRD-10PC' => 7]);
        // Shopee holds only two of the three (the 1pc was deleted there).
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 900, 'shopee_model_id' => 52, 'sku' => 'PRD-5PC']);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 900, 'shopee_model_id' => 53, 'sku' => 'PRD-10PC']);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_quantity', $pid))
            ->assertRedirect();

        $flash = (string) session('status');
        $this->assertStringContainsString('Stock synced to Shopee (2 model(s))', $flash);
        $this->assertStringContainsString('PRD-1PC (not on Shopee)', $flash, 'the skip is named, never silent');

        $calls = $this->stockCalls();
        $this->assertCount(1, $calls);
        $this->assertCount(2, $calls[0]['body']['stock_list'], 'only what Shopee has is sent');
        $this->assertSame([52, 53], array_column($calls[0]['body']['stock_list'], 'model_id'));
    }

    /** failure_list: each link row wears its OWN model's verdict; a failed model is never a green row. */
    public function test_per_model_failures_are_recorded_per_link(): void
    {
        $pid = $this->productWithVariations(['A-1' => 5, 'A-2' => 6]);
        $good = ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 901, 'shopee_model_id' => 61, 'sku' => 'A-1']);
        $bad = ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 901, 'shopee_model_id' => 62, 'sku' => 'A-2']);

        $this->postResponses['/api/v2/product/update_stock'] = ['ok' => true, 'status' => 200, 'body' => [
            'error' => '', 'message' => '',
            'response' => ['failure_list' => [['model_id' => 62, 'failed_reason' => 'model is under promotion']]],
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_quantity', $pid))
            ->assertRedirect();

        $this->assertTrue((bool) $good->fresh()->last_sync_ok);
        $this->assertFalse((bool) $bad->fresh()->last_sync_ok);
        $this->assertStringContainsString('promotion', (string) $bad->fresh()->last_sync_error_message);
    }

    /** The model_id-is-mandatory incident: the push repairs, retries once, and says so. */
    public function test_a_model_level_refusal_heals_and_retries_once(): void
    {
        $pid = $this->productWithVariations(['B-1' => 5, 'B-2' => 6]);
        // A stale parent-only link on what is now a model-level item.
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 902, 'shopee_model_id' => null, 'sku' => 'PAR']);

        $attempt = 0;
        $this->postResponses['/api/v2/product/update_stock'] = function (array $body) use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                return ['ok' => true, 'status' => 200, 'body' => ['error' => 'error_param', 'message' => 'model_id is mandatory if item is under model level']];
            }

            return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => []]];
        };
        $this->getResponses['/api/v2/product/get_model_list'] = ['ok' => true, 'status' => 200, 'body' => [
            'response' => ['model' => [
                ['model_id' => 71, 'model_sku' => 'B-1'],
                ['model_id' => 72, 'model_sku' => 'B-2'],
            ]],
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_quantity', $pid))
            ->assertRedirect();

        $flash = (string) session('status');
        $this->assertStringContainsString('repaired mid-push', $flash, 'the heal is reported, never silent');

        // The links now match Shopee's shape, and the retry carried real model ids.
        $models = ShopeeProductLink::query()->where('product_id', $pid)->pluck('shopee_model_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame([71, 72], $models);
        $calls = $this->stockCalls();
        $this->assertCount(2, $calls, 'one refusal, one retry - never a loop');
        $this->assertSame([71, 72], array_column($calls[1]['body']['stock_list'], 'model_id'));
    }

    /** A refusal that the repair cannot explain is reported, not retried forever. */
    public function test_a_refusal_without_a_model_cause_is_not_retried(): void
    {
        $pid = $this->productWithVariations(['C-1' => 5]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 903, 'shopee_model_id' => 81, 'sku' => 'C-1']);

        $this->postResponses['/api/v2/product/update_stock'] = ['ok' => true, 'status' => 200, 'body' => [
            'error' => 'error_auth', 'message' => 'Invalid access token',
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_quantity', $pid))
            ->assertRedirect();

        $this->assertCount(1, $this->stockCalls(), 'no blind retry');
        $this->assertStringContainsString('Invalid access token', (string) session('error'));
    }

    /** Price keeps the listing's own rule and the same ledger. */
    public function test_price_push_keeps_the_listing_rule_and_names_skips(): void
    {
        $pid = $this->productWithVariations(['D-1' => 5, 'D-2' => 6]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 904, 'shopee_model_id' => 91, 'sku' => 'D-1']);
        ShopeeListing::create(['product_id' => $pid, 'markup_percent' => 10, 'markup_fixed' => 0]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_price', $pid))
            ->assertRedirect();

        $flash = (string) session('status');
        $this->assertStringContainsString('Price synced to Shopee (1 model(s))', $flash);
        $this->assertStringContainsString('D-2 (not on Shopee)', $flash);

        $priceCalls = array_values(array_filter($this->sentPosts, fn ($c) => $c['path'] === '/api/v2/product/update_price'));
        $this->assertCount(1, $priceCalls);
        $this->assertSame(110.0, (float) $priceCalls[0]['body']['price_list'][0]['original_price'], 'base 100 + the listing\'s 10%');
    }
}
