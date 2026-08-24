<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Shopee half of quest 2 (#146, 2026-08-25). The rule lives on the
 * listing (a percent and a window), the promo price is computed at push
 * time from the listing's own price rule, and Shopee's shape is honored:
 * a NAMED promotion object per window that listings JOIN. Two listings
 * on the same sale share ONE object; ending removes the item and clears
 * the rule; a rule that starts too soon is refused in plain words
 * because Shopee would refuse it in strange ones.
 */
class ShopeeDiscountTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{path: string, body: array}> */
    private array $sentPayloads = [];

    /** What the fake marketplace answers per POST path (array or closure). */
    public array $postResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);

        $this->postResponses = [
            '/api/v2/discount/add_discount' => ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['discount_id' => 660011]]],
            '/api/v2/discount/add_discount_item' => ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['count' => 1, 'error_list' => []]]],
            '/api/v2/discount/delete_discount_item' => ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['error_list' => []]]],
        ];

        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->recordPayload($path, $body);

                $answer = $this->test->postResponses[$path] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($body);
                }

                return $answer
                    ?? ['ok' => false, 'status' => 500, 'body' => ['error' => 'unexpected', 'message' => 'unexpected POST ' . $path]];
            }
            public function signShop(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);
    }

    public function recordPayload(string $path, array $body): void
    {
        $this->sentPayloads[] = ['path' => $path, 'body' => $body];
    }

    private function payloadsFor(string $path): array
    {
        return array_values(array_filter($this->sentPayloads, fn ($p) => $p['path'] === $path));
    }

    private int $groupSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Discount pushers ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(float $price = 300.0, string $suffix = 'A'): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'DISC-' . $suffix, 'sku' => 'DISC-SKU-' . $suffix, 'quantity' => 5, 'price' => $price,
            'status' => 1, 'image' => 'catalog/none.png', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Discounted product ' . $suffix, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    /** A linked listing carrying a saved rule; window defaults to a valid one. */
    private function seedListedWithRule(int $productId, int $itemId, array $overrides = []): ShopeeListing
    {
        ShopeeProductLink::create(['product_id' => $productId, 'shopee_item_id' => $itemId, 'sku' => 'DISC-SKU']);

        $listing = ShopeeListing::create(array_merge([
            'product_id' => $productId,
            'shopee_category_id' => 100001,
            'logistic_ids' => [8000],
        ], $overrides));

        return $listing;
    }

    public function test_push_creates_the_promotion_and_joins_the_item_at_the_computed_price(): void
    {
        $productId = $this->seedProduct(300.0);
        $starts = now()->addHours(2)->startOfMinute();
        $ends = now()->addDays(3)->startOfMinute();
        // Price rule +50% makes the listed price 450; 10% off makes 405.
        $this->seedListedWithRule($productId, 990077, [
            'markup_percent' => 50,
            'sale_percent' => 10, 'sale_starts_at' => $starts, 'sale_ends_at' => $ends,
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_discount', $productId), ['action' => 'push']);

        $r->assertSessionHas('status');

        $creates = $this->payloadsFor('/api/v2/discount/add_discount');
        $this->assertCount(1, $creates);
        $this->assertSame($starts->timestamp, $creates[0]['body']['start_time']);
        $this->assertSame($ends->timestamp, $creates[0]['body']['end_time']);
        $this->assertStringStartsWith('VentaSync', $creates[0]['body']['discount_name']);

        $items = $this->payloadsFor('/api/v2/discount/add_discount_item');
        $this->assertCount(1, $items);
        $this->assertSame(660011, $items[0]['body']['discount_id']);
        $row = $items[0]['body']['item_list'][0];
        $this->assertSame(990077, $row['item_id']);
        $this->assertSame(0, $row['purchase_limit']);
        $this->assertEqualsWithDelta(405.0, $row['item_promotion_price'], 0.001);

        $this->assertSame(660011, (int) ShopeeListing::where('product_id', $productId)->value('shopee_discount_id'));
    }

    public function test_two_listings_on_the_same_window_share_one_promotion_object(): void
    {
        $starts = now()->addHours(2)->startOfMinute();
        $ends = now()->addDays(3)->startOfMinute();

        // Listing A already pushed this window and holds the object.
        $productA = $this->seedProduct(300.0, 'A');
        $this->seedListedWithRule($productA, 990077, [
            'sale_percent' => 10, 'sale_starts_at' => $starts, 'sale_ends_at' => $ends,
            'shopee_discount_id' => 660011,
        ]);

        // Listing B saved the SAME window; its push must only JOIN.
        $productB = $this->seedProduct(200.0, 'B');
        $this->seedListedWithRule($productB, 990088, [
            'sale_percent' => 25, 'sale_starts_at' => $starts, 'sale_ends_at' => $ends,
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_discount', $productB), ['action' => 'push']);

        $r->assertSessionHas('status');
        $this->assertCount(0, $this->payloadsFor('/api/v2/discount/add_discount'));

        $items = $this->payloadsFor('/api/v2/discount/add_discount_item');
        $this->assertCount(1, $items);
        $this->assertSame(660011, $items[0]['body']['discount_id']);
        $this->assertEqualsWithDelta(150.0, $items[0]['body']['item_list'][0]['item_promotion_price'], 0.001);

        $this->assertSame(660011, (int) ShopeeListing::where('product_id', $productB)->value('shopee_discount_id'));
    }

    public function test_a_variation_product_sends_per_model_promo_prices(): void
    {
        $productId = $this->seedProduct(300.0);
        $starts = now()->addHours(2)->startOfMinute();
        $ends = now()->addDays(3)->startOfMinute();

        DB::table('product_option_combinations')->insert([
            ['product_id' => $productId, 'sku' => 'DISC-RED', 'status' => 1, 'image' => '', 'quantity' => 3, 'absolute_price' => 500, 'subtract' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $productId, 'sku' => 'DISC-BLUE', 'status' => 1, 'image' => '', 'quantity' => 2, 'absolute_price' => 0, 'subtract' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        ShopeeProductLink::create(['product_id' => $productId, 'shopee_item_id' => 990077, 'shopee_model_id' => 111, 'sku' => 'DISC-RED']);
        ShopeeProductLink::create(['product_id' => $productId, 'shopee_item_id' => 990077, 'shopee_model_id' => 222, 'sku' => 'DISC-BLUE']);
        ShopeeListing::create([
            'product_id' => $productId, 'shopee_category_id' => 100001, 'logistic_ids' => [8000],
            'sale_percent' => 20, 'sale_starts_at' => $starts, 'sale_ends_at' => $ends,
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_discount', $productId), ['action' => 'push']);

        $r->assertSessionHas('status');
        $items = $this->payloadsFor('/api/v2/discount/add_discount_item');
        $this->assertCount(1, $items);
        $row = $items[0]['body']['item_list'][0];
        $this->assertArrayNotHasKey('item_promotion_price', $row);

        $byModel = collect($row['model_list'])->keyBy('model_id');
        // RED has its own price 500 -> 400; BLUE falls back to core 300 -> 240.
        $this->assertEqualsWithDelta(400.0, $byModel[111]['model_promotion_price'], 0.001);
        $this->assertEqualsWithDelta(240.0, $byModel[222]['model_promotion_price'], 0.001);
    }

    public function test_end_removes_the_item_and_clears_the_rule(): void
    {
        $productId = $this->seedProduct();
        $this->seedListedWithRule($productId, 990077, [
            'sale_percent' => 10,
            'sale_starts_at' => now()->addHours(2), 'sale_ends_at' => now()->addDays(3),
            'shopee_discount_id' => 660011,
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_discount', $productId), ['action' => 'end']);

        $r->assertSessionHas('status');
        $deletes = $this->payloadsFor('/api/v2/discount/delete_discount_item');
        $this->assertCount(1, $deletes);
        $this->assertSame(660011, $deletes[0]['body']['discount_id']);
        $this->assertSame(990077, $deletes[0]['body']['item_id']);

        $listing = ShopeeListing::where('product_id', $productId)->first();
        $this->assertNull($listing->sale_percent);
        $this->assertNull($listing->sale_starts_at);
        $this->assertNull($listing->sale_ends_at);
        $this->assertNull($listing->shopee_discount_id);
    }

    public function test_push_without_a_saved_rule_is_refused_before_any_call(): void
    {
        $productId = $this->seedProduct();
        $this->seedListedWithRule($productId, 990077);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_discount', $productId), ['action' => 'push']);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Set the discount first', session('error'));
        $this->assertSame([], $this->sentPayloads);
    }

    public function test_a_start_less_than_an_hour_away_is_refused_in_plain_words(): void
    {
        $productId = $this->seedProduct();
        $this->seedListedWithRule($productId, 990077, [
            'sale_percent' => 10,
            'sale_starts_at' => now()->addMinutes(10), 'sale_ends_at' => now()->addDays(3),
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_discount', $productId), ['action' => 'push']);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('at least an hour from now', session('error'));
        $this->assertSame([], $this->sentPayloads);
    }
}
