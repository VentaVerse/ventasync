<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase F parity (#184): TikTok's stock and price pushes get the same
 * guarantees Shopee's have - skips named never silent, orphan map keys
 * never pushed wrong figures, the __single__ degradation heals with one
 * product read, per-SKU refusals become verdicts, and a failed batch can
 * no longer flash green.
 */
class TiktokStockPushHealTest extends TestCase
{
    use RefreshDatabase;

    public array $getProductAnswers = [];

    /** @var array<int, array{method: string, product: string, skus: array}> */
    public array $calls = [];

    public array $updateAnswers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3),
        ]);

        $test = $this;
        $fake = new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function getProduct(string $appKey, string $appSecret, string $accessToken, string $productId, ?string $shopCipher = null): array
            {
                $this->test->calls[] = ['method' => 'getProduct', 'product' => $productId, 'skus' => []];

                return $this->test->getProductAnswers[$productId]
                    ?? ['ok' => false, 'status' => 500, 'body' => ['code' => 1, 'message' => 'unexpected getProduct']];
            }
            public function updateInventory(string $appKey, string $appSecret, string $accessToken, string $productId, array $skus, ?string $shopCipher = null): array
            {
                $this->test->calls[] = ['method' => 'inventory', 'product' => $productId, 'skus' => $skus];
                $answer = $this->test->updateAnswers['inventory'] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($skus);
                }

                return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success']];
            }
            public function updatePrice(string $appKey, string $appSecret, string $accessToken, string $productId, array $skus, ?string $shopCipher = null): array
            {
                $this->test->calls[] = ['method' => 'price', 'product' => $productId, 'skus' => $skus];

                return $this->test->updateAnswers['price'] ?? ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success']];
            }
        };
        $this->app->instance(TikTokClient::class, $fake);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TT push group ' . uniqid()]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'view_tiktok/product', 'manage_tiktok/product',
                'view_tiktok/listing', 'manage_tiktok/listing',
                'view_tiktok/product_group', 'manage_tiktok/product_group',
            ])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** A product whose combinations carry the given sku => qty pairs. */
    private function comboProduct(array $skuQty): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => 'TT', 'sku' => 'TT', 'quantity' => 99, 'price' => 100,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'TT push product', 'description' => '',
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

    private function inventoryCalls(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'inventory'));
    }

    /** A variation the catalogue has and TikTok Shop lacks fails the product whole, by name (the owner, 2026-09-07). */
    public function test_a_variation_missing_from_the_map_fails_the_product_whole(): void
    {
        $pid = $this->comboProduct(['TT-1' => 5, 'TT-2' => 8]);
        TikTokListing::create(['product_id' => $pid, 'tiktok_product_id' => 'tt-1', 'tiktok_sku_id' => json_encode(['TT-1' => 's1'])]);
        // The heal will ask TikTok; it answers with the same single SKU, so
        // TT-2 genuinely is not on TikTok Shop.
        $this->getProductAnswers['tt-1'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'data' => [
            'status' => 'ACTIVATE', 'title' => 'TT', 'skus' => [['id' => 's1', 'seller_sku' => 'TT-1', 'inventory' => []]],
        ]]];

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.push_stock', $pid))
            ->assertRedirect();

        $this->assertNull(session('status'));
        $flash = (string) session('error');
        $this->assertStringContainsString('Variations do not match TikTok Shop: TT-2 (not on TikTok Shop)', $flash);
        $this->assertStringContainsString('Nothing was pushed', $flash);
        $this->assertSame([], $this->inventoryCalls(), 'not even the SKU TikTok has');
    }

    /** An orphan map key (on TikTok Shop, gone from the catalogue) fails the product whole - never a zero, never a base price. */
    public function test_an_orphan_map_key_fails_the_product_whole(): void
    {
        $pid = $this->comboProduct(['TT-1' => 5]);
        TikTokListing::create(['product_id' => $pid, 'tiktok_product_id' => 'tt-2',
            'tiktok_sku_id' => json_encode(['TT-1' => 's1', 'TT-GONE' => 's9'])]);

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.push_stock', $pid))
            ->assertRedirect();

        $flash = (string) session('error');
        $this->assertStringContainsString('TT-GONE (on TikTok Shop but not in the catalogue)', $flash);
        $this->assertSame([], $this->inventoryCalls(), 'nothing is sent: not the orphan, not its twin');
    }

    /** The __single__ degradation heals: one read, the real map, per-SKU figures. */
    public function test_a_single_key_map_on_a_variation_product_heals_first(): void
    {
        $pid = $this->comboProduct(['TT-1' => 5, 'TT-2' => 8]);
        $listing = TikTokListing::create(['product_id' => $pid, 'tiktok_product_id' => 'tt-3', 'tiktok_sku_id' => 's-solo']);
        $this->getProductAnswers['tt-3'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'data' => [
            'status' => 'ACTIVATE', 'title' => 'TT', 'skus' => [
                ['id' => 's1', 'seller_sku' => 'TT-1', 'inventory' => []],
                ['id' => 's2', 'seller_sku' => 'TT-2', 'inventory' => []],
            ],
        ]]];

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.push_stock', $pid))
            ->assertRedirect();

        $flash = (string) session('status');
        $this->assertStringContainsString('repaired mid-push', $flash);

        $calls = $this->inventoryCalls();
        $this->assertCount(1, $calls);
        $this->assertEqualsCanonicalizing(['s1', 's2'], array_column($calls[0]['skus'], 'id'),
            'each variation gets its own SKU, not the aggregate on one');

        $map = json_decode((string) $listing->fresh()->tiktok_sku_id, true);
        $this->assertSame(['TT-1' => 's1', 'TT-2' => 's2'], $map, 'the repaired map is stored by seller SKU');
    }

    /** A sku-shaped refusal heals once and retries once - never a loop. */
    public function test_a_sku_refusal_heals_and_retries_once(): void
    {
        $pid = $this->comboProduct(['TT-1' => 5]);
        TikTokListing::create(['product_id' => $pid, 'tiktok_product_id' => 'tt-4', 'tiktok_sku_id' => json_encode(['TT-1' => 'stale-id'])]);
        $this->getProductAnswers['tt-4'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'data' => [
            'status' => 'ACTIVATE', 'title' => 'TT', 'skus' => [['id' => 's-new', 'seller_sku' => 'TT-1', 'inventory' => []]],
        ]]];
        $attempt = 0;
        $this->updateAnswers['inventory'] = function (array $skus) use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                return ['ok' => true, 'status' => 200, 'body' => ['code' => 12052, 'message' => 'sku not found']];
            }

            return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success']];
        };

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.push_stock', $pid))
            ->assertRedirect();

        $this->assertStringContainsString('repaired mid-push', (string) session('status'));
        $calls = $this->inventoryCalls();
        $this->assertCount(2, $calls, 'one refusal, one retry');
        $this->assertSame(['s-new'], array_column($calls[1]['skus'], 'id'), 'the retry carries the fresh id');
    }

    /** The listing page says how much of the catalogue stands on TikTok Shop, and names the verb. */
    public function test_the_listing_page_carries_coverage_and_points_at_update(): void
    {
        $pid = $this->comboProduct(['TT-1' => 5, 'TT-2' => 8]);
        TikTokListing::create(['product_id' => $pid, 'tiktok_product_id' => 'tt-cov', 'tiktok_sku_id' => json_encode(['TT-1' => 's1'])]);
        $this->getProductAnswers['tt-cov'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'data' => [
            'status' => 'ACTIVATE', 'title' => 'TT', 'skus' => [['id' => 's1', 'seller_sku' => 'TT-1', 'inventory' => []]],
        ]]];

        $page = $this->actingAs($this->manager())
            ->get(route('ext.tiktok.listings.edit', $pid))->assertOk();

        /* The coverage figure and the sentence about it were both reports
           on the live item, and both left the listing page with the rest
           of the health surface on 2026-09-09 (the owner: "Nothing about
           health"). The push behaviour they described is unchanged - an
           update still carries every catalogue variation - and the heal
           tests below are where that is actually proven. */
        $page->assertDontSee('1 of 2 variations');
        $page->assertDontSee('carries every catalogue variation, missing ones included');
    }

    /** A failed group batch can no longer flash green. */
    public function test_a_failed_group_batch_does_not_flash_green(): void
    {
        $pid = $this->comboProduct(['TT-1' => 5]);
        $grp = TikTokProductGroup::create(['name' => 'Tone group', 'tiktok_category_id' => '900009']);
        $pivot = TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $grp->id, 'product_id' => $pid,
            'tiktok_product_id' => 'tt-5', 'tiktok_sku_id' => json_encode(['TT-1' => 's1']),
            'sync_status' => 'pushed',
        ]);
        $this->updateAnswers['inventory'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 500, 'message' => 'internal error']];

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.product-groups.pushStock', $grp->id))
            ->assertRedirect();

        $this->assertNull(session('status'), '0 ok must not wear the green tick');
        $this->assertStringContainsString('0 ok, 1 failed', (string) session('error'));
        // And the failure leaves a durable trace on the pivot at last.
        $this->assertSame('error', $pivot->fresh()->sync_status);
        $this->assertStringContainsString('internal error', (string) $pivot->fresh()->push_error);
    }

    /** The one price rule on the button too (2026-09-07): no listing rule means the group's, never the bare catalog price. */
    public function test_the_price_button_prices_a_product_without_a_listing_rule_by_its_group(): void
    {
        $pid = $this->comboProduct(['TP-1' => 5]);
        $grp = TikTokProductGroup::create(['name' => 'Priced group', 'tiktok_category_id' => '900009', 'markup_percent' => 20]);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $grp->id, 'product_id' => $pid,
            'tiktok_product_id' => 'tt-9', 'tiktok_sku_id' => json_encode(['TP-1' => 's1']), 'sync_status' => 'pushed',
        ]);

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_price', $pid))->assertRedirect();

        $calls = array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'price'));
        $this->assertCount(1, $calls);
        // The combination's own price (100 + 5) through the group's 20%.
        $this->assertSame('126', (string) $calls[0]['skus'][0]['price']['amount']);
    }
}
