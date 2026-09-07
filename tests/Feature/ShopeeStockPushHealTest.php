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

    /**
     * The deleted-1pc incident, ruled the other way (the owner, 2026-09-07): a
     * product whose variations differ from Shopee's fails WHOLE and names
     * the difference, nothing of it is sent, and the operator makes the
     * catalog and Shopee agree. A partial push named the skip but not the
     * product, and nobody could find it among two thousand rows.
     */
    public function test_a_product_whose_variations_do_not_match_shopee_fails_whole_and_names_them(): void
    {
        $pid = $this->productWithVariations(['PRD-1PC' => 5, 'PRD-5PC' => 6, 'PRD-10PC' => 7]);
        // Shopee holds only two of the three (the 1pc was deleted there).
        $five = ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 900, 'shopee_model_id' => 52, 'sku' => 'PRD-5PC']);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 900, 'shopee_model_id' => 53, 'sku' => 'PRD-10PC']);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_quantity', $pid))
            ->assertRedirect();

        $this->assertNull(session('status'), 'nothing went up, so no green tick');
        $flash = (string) session('error');
        $this->assertStringContainsString('Variations do not match Shopee: PRD-1PC (not on Shopee)', $flash);
        $this->assertStringContainsString('Nothing was pushed', $flash);
        $this->assertSame([], $this->stockCalls(), 'not even the two Shopee has');

        $fresh = $five->fresh();
        $this->assertFalse((bool) $fresh->last_sync_ok, 'every link row of the product wears the verdict, so the error filter finds it');
        $this->assertSame('VARIATIONS_MISMATCH', $fresh->last_sync_error_code);
        $this->assertStringContainsString('PRD-1PC (not on Shopee)', (string) $fresh->last_sync_error_message);
    }

    /** The other direction, and the cron: a model Shopee has that the catalogue lacks fails the product whole too. */
    public function test_the_cron_fails_a_product_with_a_model_the_catalogue_lacks_and_pushes_the_rest(): void
    {
        $gone = $this->productWithVariations(['G-1' => 5]);
        ShopeeProductLink::create(['product_id' => $gone, 'shopee_item_id' => 910, 'shopee_model_id' => 1, 'sku' => 'G-1']);
        ShopeeProductLink::create(['product_id' => $gone, 'shopee_item_id' => 910, 'shopee_model_id' => 2, 'sku' => 'G-OLD']);
        $fine = $this->productWithVariations(['F-1' => 3]);
        ShopeeProductLink::create(['product_id' => $fine, 'shopee_item_id' => 911, 'shopee_model_id' => 3, 'sku' => 'F-1']);

        $this->artisan('shopee:push-stock')->assertExitCode(1);

        $calls = $this->stockCalls();
        $this->assertCount(1, $calls, 'the whole product is held back; the other product still goes up');
        $this->assertSame(911, (int) $calls[0]['body']['item_id']);
        $message = (string) ShopeeProductLink::query()->where('product_id', $gone)->value('last_sync_error_message');
        $this->assertStringContainsString('G-OLD (on Shopee but not in the catalogue)', $message);
        $this->assertStringContainsString('delete it on Shopee, then Refresh from Shopee', $message, 'the fix for this direction is named');
        $this->assertTrue((bool) ShopeeProductLink::query()->where('product_id', $fine)->value('last_sync_ok'));
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

    /** The listing page says how much of the catalogue stands on Shopee, and offers the repair. */
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

        $page->assertSee('2 of 3 variations');
        $page->assertSee('E-3');                       // the missing side, named
        $page->assertSee('Not linked');                // Shopee's STRANGER model, flagged
        $page->assertSee('Re-read variations from Shopee');

        // The verb runs the same repair the pushes self-heal with.
        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.reread_variations', $pid))
            ->assertRedirect();
        $this->assertStringContainsString('2 variation(s) linked', (string) session('status'));
        $this->assertStringContainsString('STRANGER', (string) session('status'));
    }

    /** Price keeps the listing's own rule on every model. */
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

    /** Push Price is its own automation (2026-09-07): the listing's rule, else the group's, else the catalog price. */
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

    /**
     * The live case (the owner, 2026-09-07): a variation deleted in the seller
     * centre left a link Shopee refused on every run. Shopee's per-model
     * "does not exist" now drops that link and fails the product whole, by
     * name, so the next run tells the operator which variation to delete.
     */
    public function test_a_model_shopee_says_is_gone_is_dropped_and_the_product_fails_whole_by_name(): void
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
        $this->assertFalse((bool) $fresh->last_sync_ok);
        $this->assertSame('VARIATIONS_MISMATCH', $fresh->last_sync_error_code);
        $this->assertStringContainsString('E-2 (not on Shopee)', (string) $fresh->last_sync_error_message);
        $flash = (string) session('error');
        $this->assertStringContainsString('E-2 (not on Shopee)', $flash);
        $this->assertStringContainsString('add it with Push update, or delete it from the catalog', $flash, 'the fix for this direction is named');
        $this->assertStringNotContainsString('Refresh from Shopee', $flash, 'the other direction\'s fix is not');
    }

    private function automationsManager(): User
    {
        $group = UserGroup::create(['name' => 'Shopee automations ' . (++$this->seq)]);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_shopee/settings', 'view_shopee/settings'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * Run now in steps (the owner, 2026-09-07): Push Stock walks its products ten
     * per request, the page draws "Product 10 of 12", the job row is stamped
     * when the run ends, and Stop keeps what was pushed.
     */
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

        // A refused model counts as a failed product and the job ends red.
        $this->postResponses['/api/v2/product/update_stock'] = ['ok' => true, 'status' => 200, 'body' => ['error' => 'error_auth', 'message' => 'Invalid access token']];
        $again = $this->actingAs($user)->postJson(route('automations.run', $job->id))->assertOk()->json('run');
        $this->assertSame(10, $again['failed']);
        $this->actingAs($user)->postJson(route('automations.run_step', ['run' => $again['id']]))->assertOk()->assertJsonPath('run.status', 'done')->assertJsonPath('run.failed', 12);
        $this->assertFalse((bool) $job->fresh()->last_run_ok);

        // Stop keeps what was pushed and stamps the job.
        $this->postResponses['/api/v2/product/update_stock'] = null;
        $third = $this->actingAs($user)->postJson(route('automations.run', $job->id))->assertOk()->json('run');
        $this->actingAs($user)->postJson(route('automations.run_stop', ['run' => $third['id']]))->assertOk()->assertJsonPath('run.status', 'stopped');
        $this->assertSame('Stopped after 10 products of 12: 10 ok.', \App\Services\AutomationRunner::outcome(\App\Models\AutomationRun::find($third['id'])));

        // A job nobody can walk in steps runs whole and answers its outcome in one line.
        $whole = \App\Models\ScheduledJob::create([
            'integration' => 'shopee', 'display_name' => 'Say something', 'command' => 'inspire',
            'cadence_value' => 1, 'cadence_unit' => 'hour', 'enabled' => false, 'options' => [],
        ]);
        $this->actingAs($user)->postJson(route('automations.run', $whole->id))->assertOk()->assertJsonPath('done', true)->assertJsonPath('ok', true);

        // Someone who cannot manage the channel cannot step its run.
        $this->actingAs($this->manager())->postJson(route('automations.run_step', ['run' => $third['id']]))->assertForbidden();
    }

    /** Every push command on every channel can be walked, and the tab carries the strip. */
    public function test_every_push_command_is_steppable_and_the_tab_carries_the_strip(): void
    {
        foreach ([
            'shopee/Commands/ShopeePushStock', 'shopee/Commands/ShopeePushPrice',
            'lazada/Commands/LazadaPushStock', 'lazada/Commands/LazadaPushPrice',
            'tiktok/Commands/TikTokPushStock', 'tiktok/Commands/TikTokPushPrice',
            'venta/Commands/VentaPushStock', 'venta/Commands/VentaPushPrice',
            'woocommerce/Commands/WoocommercePushStock', 'woocommerce/Commands/WoocommercePushPrice',
            'opencart/Commands/OpenCartPushQty', 'opencart/Commands/OpenCartPushPrice',
        ] as $file) {
            $this->assertStringContainsString('SteppableAutomation', (string) file_get_contents(base_path("extensions/{$file}.php")), $file);
        }
        $tab = (string) file_get_contents(base_path('resources/views/partials/_automations_tab.blade.php'));
        $this->assertStringContainsString('data-automation-run', $tab);
        $this->assertStringContainsString('data-ar-ok', $tab, 'one sentence and OK, for every automation');
        $this->assertStringNotContainsString('data-of-strip', $tab, 'no bar on the Automations tab (the owner, 2026-09-07: unity)');
        $this->assertStringContainsString("import './automation-run';", (string) file_get_contents(base_path('resources/js/app.js')));
    }

    /** Sync Orders walks its phases one per request, so the bar is real and the console never reaches the page. */
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
        $this->assertSame(3, $first['total'], 'orders, fees, payouts; no returns, as the row says');
        $this->assertSame(1, $first['done'], 'one phase per request');
        $this->assertSame('running', $first['status']);

        $second = $this->actingAs($user)->postJson(route('automations.run_step', ['run' => $first['id']]))->assertOk()->json('run');
        $this->assertSame(2, $second['done']);
        $third = $this->actingAs($user)->postJson(route('automations.run_step', ['run' => $first['id']]))->assertOk();
        $this->assertSame('done', $third->json('run.status'));
        $this->assertSame('3 steps: 3 ok.', $third->json('outcome'));
        $this->assertTrue((bool) $job->fresh()->last_run_ok);
        $this->assertStringNotContainsString('===', implode(' ', $third->json('run.ledger')), 'the console never reaches the page');
    }
}
