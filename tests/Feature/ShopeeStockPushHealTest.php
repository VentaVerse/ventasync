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

class ShopeeStockPushHealTest extends TestCase
{
    use RefreshDatabase;

    public array $postResponses = [];

    public array $getResponses = [];

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

    public function test_a_model_reread_remembers_every_model_the_item_holds(): void
    {
        $pid = $this->productWithVariations(['RR-1' => 1, 'RR-2' => 2, 'RR-3' => 3]);
        $storeId = (int) ShopeeSetting::query()->value('id');
        $auth = ['mode' => 'live', 'partner_id' => 1, 'partner_key' => 'k', 'access_token' => 't', 'shop_id' => 2, 'store_id' => $storeId];
        $this->getResponses['/api/v2/product/get_model_list'] = ['ok' => true, 'status' => 200, 'body' => [
            'response' => ['model' => [
                ['model_id' => 71, 'model_sku' => 'RR-1'],
                ['model_id' => 72, 'model_sku' => 'RR-2'],
                ['model_id' => 73, 'model_sku' => 'STRANGER'],
            ]],
        ]];
        $repair = app(\Extensions\shopee\Services\Shopee\ShopeeModelLinkRepair::class);

        $this->assertSame('models', $repair->forItem($auth, $pid, 900, ['rr-1', 'rr-2', 'rr-3'])['mode']);
        $missing = \App\Integrations\Listings\ListingVariations::missing('shopee', $storeId, [$pid]);
        $this->assertSame(['RR-3'], array_column($missing[$pid] ?? [], 'sku'));
        $this->assertStringContainsString('STRANGER', (string) DB::table(\App\Integrations\Listings\ListingVariations::STORE_SKUS)->where('product_id', $pid)->value('skus'), 'an unmatched model is still held');

        $this->getResponses['/api/v2/product/get_model_list'] = ['ok' => false, 'status' => 500, 'body' => ['error' => 'error', 'message' => 'down']];
        $this->assertArrayHasKey('error', $repair->forItem($auth, $pid, 900, ['rr-1', 'rr-2', 'rr-3']));
        $missing = \App\Integrations\Listings\ListingVariations::missing('shopee', $storeId, [$pid]);
        $this->assertSame(['RR-3'], array_column($missing[$pid] ?? [], 'sku'));
    }

    public function test_a_variation_missing_from_shopee_is_left_alone_and_the_rest_go_up(): void
    {
        $pid = $this->productWithVariations(['PRD-1PC' => 5, 'PRD-5PC' => 6, 'PRD-10PC' => 7]);
        $five = ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 900, 'shopee_model_id' => 52, 'sku' => 'PRD-5PC']);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 900, 'shopee_model_id' => 53, 'sku' => 'PRD-10PC']);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_quantity', $pid))
            ->assertRedirect();

        $this->assertNull(session('error'));
        $calls = $this->stockCalls();
        $this->assertCount(1, $calls);
        $this->assertSame([52, 53], array_column($calls[0]['body']['stock_list'], 'model_id'));
        $this->assertTrue((bool) $five->fresh()->last_sync_ok);
    }

    public function test_the_cron_leaves_a_model_the_catalogue_lacks_alone_and_pushes_the_rest(): void
    {
        $gone = $this->productWithVariations(['G-1' => 5]);
        ShopeeProductLink::create(['product_id' => $gone, 'shopee_item_id' => 910, 'shopee_model_id' => 1, 'sku' => 'G-1']);
        ShopeeProductLink::create(['product_id' => $gone, 'shopee_item_id' => 910, 'shopee_model_id' => 2, 'sku' => 'G-OLD']);
        $fine = $this->productWithVariations(['F-1' => 3]);
        ShopeeProductLink::create(['product_id' => $fine, 'shopee_item_id' => 911, 'shopee_model_id' => 3, 'sku' => 'F-1']);

        $this->artisan('shopee:push-stock')->assertExitCode(0);

        $calls = collect($this->stockCalls())->keyBy(fn ($c) => (int) $c['body']['item_id']);
        $this->assertCount(2, $calls, 'both products go up');
        $this->assertSame([1], array_column($calls[910]['body']['stock_list'], 'model_id'), 'G-OLD is not asked of Shopee');
        $this->assertNull(ShopeeProductLink::query()->where('product_id', $gone)->where('sku', 'G-OLD')->value('last_sync_error_code'));
        $this->assertTrue((bool) ShopeeProductLink::query()->where('product_id', $fine)->value('last_sync_ok'));
    }

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

    public function test_a_model_level_refusal_heals_and_retries_once(): void
    {
        $pid = $this->productWithVariations(['B-1' => 5, 'B-2' => 6]);
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

        $models = ShopeeProductLink::query()->where('product_id', $pid)->pluck('shopee_model_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame([71, 72], $models);
        $calls = $this->stockCalls();
        $this->assertCount(2, $calls, 'one refusal, one retry - never a loop');
        $this->assertSame([71, 72], array_column($calls[1]['body']['stock_list'], 'model_id'));
    }

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

    public function test_the_listing_page_carries_coverage_and_the_reread_verb(): void
    {
        $pid = $this->productWithVariations(['E-1' => 5, 'E-2' => 6, 'E-3' => 7]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 905, 'shopee_model_id' => 95, 'sku' => 'E-1']);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 905, 'shopee_model_id' => 96, 'sku' => 'E-2']);

        $this->getResponses['/api/v2/product/get_item_base_info'] = ['ok' => true, 'status' => 200, 'body' => [
            'response' => ['item_list' => [[
                'item_status' => 'NORMAL', 'item_name' => 'Pushable product', 'item_sku' => 'PAR',
                'has_model' => true, 'update_time' => time(),
            ]]],
        ]];
        $this->getResponses['/api/v2/product/get_model_list'] = ['ok' => true, 'status' => 200, 'body' => [
            'response' => ['model' => [
                ['model_id' => 95, 'model_sku' => 'E-1'],
                ['model_id' => 96, 'model_sku' => 'E-2'],
                ['model_id' => 97, 'model_sku' => 'STRANGER'],
            ]],
        ]];

        $page = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $pid))->assertOk();

        $page->assertDontSee('2 of 3 variations');
        $page->assertDontSee('In the catalogue but not on Shopee');
        $page->assertSee('Not in your Master Catalog');
        $page->assertSee('Push missing variations');
        $page->assertSee('Re-read variations from Shopee');

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.reread_variations', $pid))
            ->assertRedirect();
        $this->assertStringContainsString('2 variation(s) linked', (string) session('status'));
        $this->assertStringContainsString('STRANGER', (string) session('status'));
    }

    public function test_price_push_keeps_the_listing_rule(): void
    {
        $pid = $this->productWithVariations(['D-1' => 5, 'D-2' => 6]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 904, 'shopee_model_id' => 91, 'sku' => 'D-1']);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 904, 'shopee_model_id' => 92, 'sku' => 'D-2']);
        ShopeeListing::create(['product_id' => $pid, 'markup_percent' => 10, 'markup_fixed' => 0]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_price', $pid))
            ->assertRedirect();

        $flash = (string) session('status');
        $this->assertStringContainsString('Price synced to Shopee (2 model(s))', $flash);

        $priceCalls = array_values(array_filter($this->sentPosts, fn ($c) => $c['path'] === '/api/v2/product/update_price'));
        $this->assertCount(1, $priceCalls);
        $this->assertSame([110.0, 110.0], array_map(fn ($m) => (float) $m['original_price'], $priceCalls[0]['body']['price_list']), 'base 100 + the listing\'s 10%');
    }

    public function test_the_price_cron_prices_by_the_listing_rule_then_the_group_then_the_catalog(): void
    {
        $a = $this->productWithVariations(['A-1' => 5]);
        ShopeeProductLink::create(['product_id' => $a, 'shopee_item_id' => 901, 'shopee_model_id' => 1, 'sku' => 'A-1']);
        ShopeeListing::create(['product_id' => $a, 'markup_percent' => 10, 'markup_fixed' => 0]);

        $b = $this->productWithVariations(['B-1' => 5]);
        ShopeeProductLink::create(['product_id' => $b, 'shopee_item_id' => 902, 'shopee_model_id' => 2, 'sku' => 'B-1']);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create(['name' => 'Grouped', 'shopee_category_id' => 100200, 'markup_percent' => 20, 'markup_fixed' => 0]);
        \Extensions\shopee\Models\ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $b]);

        $c = $this->productWithVariations(['C-1' => 5]);
        ShopeeProductLink::create(['product_id' => $c, 'shopee_item_id' => 903, 'shopee_model_id' => 3, 'sku' => 'C-1']);

        $this->artisan('shopee:push-price')->assertExitCode(0);

        $prices = collect($this->sentPosts)->where('path', '/api/v2/product/update_price')
            ->mapWithKeys(fn ($c) => [(int) $c['body']['item_id'] => (float) $c['body']['price_list'][0]['original_price']])->all();
        $this->assertSame([901 => 110.0, 902 => 120.0, 903 => 100.0], $prices);
        $this->assertSame([], array_filter($this->sentPosts, fn ($c) => $c['path'] === '/api/v2/product/update_stock'), 'the price row never sends stock');
    }

    public function test_a_model_shopee_says_is_gone_is_dropped_and_the_rest_keeps_its_verdict(): void
    {
        $pid = $this->productWithVariations(['E-1' => 5, 'E-2' => 6]);
        $alive = ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 905, 'shopee_model_id' => 71, 'sku' => 'E-1']);
        $dead = ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 905, 'shopee_model_id' => 72, 'sku' => 'E-2']);
        $this->postResponses['/api/v2/product/update_stock'] = ['ok' => true, 'status' => 200, 'body' => [
            'error' => '', 'message' => '',
            'response' => ['failure_list' => [['model_id' => 72, 'failed_reason' => 'model_id does not exist']]],
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_quantity', $pid))
            ->assertRedirect();

        $this->assertNull($dead->fresh(), 'the dead link is dropped, not retried every run');
        $fresh = $alive->fresh();
        $this->assertTrue((bool) $fresh->last_sync_ok);
        $this->assertNull($fresh->last_sync_error_code);
        $this->assertNull(session('error'));
    }

    private function automationsManager(): User
    {
        $group = UserGroup::create(['name' => 'Shopee automations ' . (++$this->seq)]);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_shopee/settings', 'view_shopee/settings'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_run_now_walks_push_stock_ten_products_per_step_and_stamps_the_job(): void
    {
        $pids = [];
        for ($i = 1; $i <= 12; $i++) {
            $pid = $this->productWithVariations(["RN-{$i}" => $i]);
            ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 1000 + $i, 'shopee_model_id' => 500 + $i, 'sku' => "RN-{$i}"]);
            $pids[] = $pid;
        }
        $job = \App\Models\ScheduledJob::create([
            'integration' => 'shopee', 'display_name' => 'Push Stock', 'command' => 'shopee:push-stock',
            'cadence_value' => 30, 'cadence_unit' => 'minute', 'enabled' => true, 'options' => [],
        ]);
        $user = $this->automationsManager();

        $first = $this->actingAs($user)->postJson(route('automations.run', $job->id))->assertOk();
        $run = $first->json('run');
        $this->assertSame('running', $run['status']);
        $this->assertSame('products', $run['label']);
        $this->assertSame(12, $run['total']);
        $this->assertSame(10, $run['done']);
        $this->assertSame(10, $run['ok']);
        $this->assertCount(10, $this->stockCalls(), 'ten products, one call each, inside the first request');
        $this->assertNull($job->fresh()->last_run_at, 'not stamped until the run ends');

        $second = $this->actingAs($user)->postJson(route('automations.run_step', ['run' => $run['id']]))->assertOk();
        $this->assertSame('done', $second->json('run.status'));
        $this->assertSame(12, $second->json('run.done'));
        $this->assertSame('12 products: 12 ok.', $second->json('outcome'));
        $this->assertCount(12, $this->stockCalls());
        $this->assertTrue((bool) $job->fresh()->last_run_ok);
        $this->assertNotNull($job->fresh()->last_run_at);

        $this->postResponses['/api/v2/product/update_stock'] = ['ok' => true, 'status' => 200, 'body' => ['error' => 'error_auth', 'message' => 'Invalid access token']];
        $again = $this->actingAs($user)->postJson(route('automations.run', $job->id))->assertOk()->json('run');
        $this->assertSame(10, $again['failed']);
        $this->actingAs($user)->postJson(route('automations.run_step', ['run' => $again['id']]))->assertOk()->assertJsonPath('run.status', 'done')->assertJsonPath('run.failed', 12);
        $this->assertFalse((bool) $job->fresh()->last_run_ok);

        $this->postResponses['/api/v2/product/update_stock'] = null;
        $third = $this->actingAs($user)->postJson(route('automations.run', $job->id))->assertOk()->json('run');
        $this->actingAs($user)->postJson(route('automations.run_stop', ['run' => $third['id']]))->assertOk()->assertJsonPath('run.status', 'stopped');
        $this->assertSame('Stopped after 10 products of 12: 10 ok.', \App\Services\AutomationRunner::outcome(\App\Models\AutomationRun::find($third['id'])));

        $whole = \App\Models\ScheduledJob::create([
            'integration' => 'shopee', 'display_name' => 'Say something', 'command' => 'inspire',
            'cadence_value' => 1, 'cadence_unit' => 'hour', 'enabled' => false, 'options' => [],
        ]);
        $this->actingAs($user)->postJson(route('automations.run', $whole->id))->assertOk()->assertJsonPath('done', true)->assertJsonPath('ok', true);

        $this->actingAs($this->manager())->postJson(route('automations.run_step', ['run' => $third['id']]))->assertForbidden();
    }

    public function test_every_push_command_is_steppable_and_the_tab_carries_the_strip(): void
    {
        foreach ([
            'shopee/Commands/ShopeePushStock', 'shopee/Commands/ShopeePushPrice',
            'lazada/Commands/LazadaPushStock', 'lazada/Commands/LazadaPushPrice',
            'tiktok/Commands/TikTokPushStock', 'tiktok/Commands/TikTokPushPrice',
            'ventacart/Commands/VentaCartPushStock', 'ventacart/Commands/VentaCartPushPrice',
            'woocommerce/Commands/WoocommercePushStock', 'woocommerce/Commands/WoocommercePushPrice',
            'opencart/Commands/OpenCartPushQty', 'opencart/Commands/OpenCartPushPrice',
        ] as $file) {
            if (! is_dir(base_path('extensions/' . strtok($file, '/')))) {
                continue;
            }
            $this->assertStringContainsString('SteppableAutomation', (string) file_get_contents(base_path("extensions/{$file}.php")), $file);
        }
        $tab = (string) file_get_contents(base_path('resources/views/partials/_automations_tab.blade.php'));
        $modal = (string) file_get_contents(base_path('resources/views/partials/automation-run-modal.blade.php'));
        $this->assertStringContainsString("@include('partials.automation-run-modal'", $tab);
        $this->assertStringContainsString('data-automation-run', $modal);
        $this->assertStringContainsString('data-ar-ok', $modal, 'one sentence and OK, for every automation');
        $this->assertStringNotContainsString('data-of-strip', $tab, 'no bar on the Automations tab (Maria, 2026-09-07: unity)');
        $this->assertStringContainsString("import './automation-run';", (string) file_get_contents(base_path('resources/js/app.js')));
    }

    public function test_run_now_walks_sync_orders_one_phase_per_request(): void
    {
        $this->getResponses['/api/v2/order/get_order_list'] = ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['order_list' => [], 'more' => false]]];
        $this->getResponses['/api/v2/returns/get_return_list'] = ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['return' => [], 'more' => false]]];
        $job = \App\Models\ScheduledJob::create([
            'integration' => 'shopee', 'display_name' => 'Sync Orders', 'command' => 'shopee:sync-orders',
            'cadence_value' => 15, 'cadence_unit' => 'minute', 'enabled' => true, 'options' => ['no_returns' => true],
        ]);
        $user = $this->automationsManager();

        $first = $this->actingAs($user)->postJson(route('automations.run', $job->id))->assertOk()->json('run');
        $this->assertSame('steps', $first['label']);
        $this->assertSame(2, $first['total'], 'orders and fees; no returns, as the row says, and payouts are their own automation');
        $this->assertSame(1, $first['done'], 'one phase per request');
        $this->assertSame('running', $first['status']);

        $second = $this->actingAs($user)->postJson(route('automations.run_step', ['run' => $first['id']]))->assertOk();
        $this->assertSame(2, $second->json('run.done'));
        $this->assertSame('done', $second->json('run.status'));
        $this->assertSame('', $second->json('outcome'), 'finished is the whole sentence; users do not know the steps');
        $this->assertTrue((bool) $job->fresh()->last_run_ok);
        $this->assertStringNotContainsString('===', implode(' ', $second->json('run.ledger')), 'the console never reaches the page');
    }

    public function test_the_price_button_prices_a_listing_without_a_rule_by_its_group(): void
    {
        $pid = $this->productWithVariations(['G-1' => 5]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 920, 'shopee_model_id' => 1, 'sku' => 'G-1']);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create(['name' => 'Priced group', 'shopee_category_id' => 100200, 'markup_percent' => 25, 'markup_fixed' => 0]);
        \Extensions\shopee\Models\ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $pid]);

        $this->actingAs($this->manager())->post(route('ext.shopee.products.sync_price', $pid))->assertRedirect();

        $this->assertStringNotContainsString('no listing price rule', (string) session('status') . (string) session('error'));
        $calls = array_values(array_filter($this->sentPosts, fn ($c) => $c['path'] === '/api/v2/product/update_price'));
        $this->assertCount(1, $calls);
        $this->assertSame(125.0, (float) $calls[0]['body']['price_list'][0]['original_price'], 'base 100 + the group\'s 25%');

        $bulk = $this->actingAs($this->manager())->post(route('ext.shopee.products.bulk_sync_price'), ['product_ids' => [$pid]])->assertRedirect();
        $this->assertStringNotContainsString('without a listing price rule', (string) session('status') . (string) session('error'));
    }

    public function test_the_price_sender_starts_a_simple_product_from_its_own_price(): void
    {
        $pid = $this->productWithVariations([]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 930, 'shopee_model_id' => null, 'sku' => 'PAR']);
        ShopeeListing::create(['product_id' => $pid, 'price' => 500, 'markup_percent' => 10]);
        $sent = function () {
            $calls = array_values(array_filter($this->sentPosts, fn ($c) => $c['path'] === '/api/v2/product/update_price'));
            $this->sentPosts = [];

            return array_map(fn ($c) => (float) $c['body']['price_list'][0]['original_price'], $calls);
        };

        $this->actingAs($this->manager())->post(route('ext.shopee.products.sync_price', $pid))->assertRedirect();
        $this->assertSame([550.0], $sent(), 'its own 500 + the listing\'s 10%');

        $this->actingAs($this->manager())->post(route('ext.shopee.products.bulk_sync_price'), ['product_ids' => [$pid]])->assertRedirect();
        $this->assertSame([550.0], $sent());

        $this->artisan('shopee:push-price')->assertExitCode(0);
        $this->assertSame([550.0], $sent());

        $storeId = (int) ShopeeSetting::query()->value('id');
        \Extensions\shopee\Services\Shopee\ShopeePushSteps::step('price', [$storeId . ':' . $pid]);
        $this->assertSame([550.0], $sent());
    }

    public function test_a_variation_product_ignores_a_stored_price(): void
    {
        $pid = $this->productWithVariations(['V-1' => 5, 'V-2' => 6]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 931, 'shopee_model_id' => 71, 'sku' => 'V-1']);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 931, 'shopee_model_id' => 72, 'sku' => 'V-2']);
        ShopeeListing::create(['product_id' => $pid, 'price' => 999, 'markup_percent' => 10]);

        $this->artisan('shopee:push-price')->assertExitCode(0);

        $calls = array_values(array_filter($this->sentPosts, fn ($c) => $c['path'] === '/api/v2/product/update_price'));
        $this->assertCount(1, $calls);
        $this->assertSame([110.0, 110.0], array_map(fn ($m) => (float) $m['original_price'], $calls[0]['body']['price_list']), 'base 100 + 10%, never the stored 999');
    }
}
