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
 * The TikTok half of quest 2 (#146, 2026-08-26). The rule (a whole
 * percent off plus a window) lives on the product's own TikTok record;
 * TikTok's DIRECT_DISCOUNT activity takes the percent itself and cuts
 * the LIVE price server-side, so no promo price is computed or stored.
 * Like the Shopee half: one activity per window that products JOIN,
 * ending removes the product and clears the rule, and refusals come in
 * plain words before any call. Listing truth stays DERIVED across the
 * group pivots - the page reads it, never rewrites it.
 */
class TiktokDiscountTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{method: string, path: string, body: array}> */
    private array $sentCalls = [];

    /** What the fake marketplace answers per "METHOD path" (array or closure). */
    public array $callResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'shop_cipher' => 'c',
        ]);

        $test = $this;
        $fake = new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            {
                return $this->test->answer('POST', $path, $body);
            }
            public function put(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            {
                return $this->test->answer('PUT', $path, $body);
            }
            public function delete(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            {
                return $this->test->answer('DELETE', $path, $body);
            }
        };
        $this->app->instance(TikTokClient::class, $fake);
    }

    public function answer(string $method, string $path, array $body): array
    {
        $this->sentCalls[] = ['method' => $method, 'path' => $path, 'body' => $body];

        $answer = $this->callResponses[$method . ' ' . $path] ?? null;
        if ($answer instanceof \Closure) {
            return $answer($body);
        }

        return $answer
            ?? ['ok' => false, 'status' => 500, 'body' => ['code' => -1, 'message' => 'unexpected ' . $method . ' ' . $path]];
    }

    private function callsFor(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->sentCalls,
            fn ($c) => $c['method'] === $method && $c['path'] === $path
        ));
    }

    private function armHappyPath(string $activityId = 'act-777'): void
    {
        $this->callResponses['POST /promotion/202309/activities'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['activity_id' => $activityId]]];
        $this->callResponses['PUT /promotion/202309/activities/' . $activityId . '/products'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => []]];
        $this->callResponses['DELETE /promotion/202309/activities/' . $activityId . '/products'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => []]];
    }

    private int $groupSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok discount pushers ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_tiktok/listing', 'view_tiktok/listing'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $suffix = 'A'): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'TTD-' . $suffix, 'sku' => 'TTD-SKU-' . $suffix, 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'TikTok discounted product ' . $suffix, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    /** A product listed on TikTok through some group's push. */
    private function seedListed(int $productId, string $tiktokProductId): void
    {
        $group = TikTokProductGroup::create(['name' => 'Discount group ' . $tiktokProductId, 'tiktok_category_id' => '900001']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $group->id, 'product_id' => $productId,
            'tiktok_product_id' => $tiktokProductId, 'sync_status' => 'pushed',
            'last_pushed_at' => now()->subHours(2),
        ]);
    }

    public function test_the_page_tells_the_derived_truth_and_carries_the_rule(): void
    {
        $productId = $this->seedProduct();
        $this->seedListed($productId, 'tt-990077');

        $r = $this->actingAs($this->manager())
            ->get(route('ext.tiktok.listings.edit', $productId));

        $r->assertOk();
        // The listing page grew its live section (#156): a listed product
        // opens on what TikTok Shop says right now, with its product id.
        $r->assertSee('On TikTok Shop right now');
        $r->assertSee('tt-990077');
        $r->assertSee('Percent off');

        $save = $this->actingAs($this->manager())
            ->put(route('ext.tiktok.listings.update', $productId), [
                'sale_percent' => 10,
                'sale_starts_at' => now()->addHours(2)->format('Y-m-d\TH:i'),
                'sale_ends_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            ]);

        $save->assertSessionHas('status');
        $listing = TikTokListing::where('product_id', $productId)->first();
        $this->assertSame(10.0, $listing->sale_percent);
        $this->assertNotNull($listing->sale_starts_at);
    }

    public function test_push_creates_the_activity_and_joins_the_product_at_the_percent(): void
    {
        $productId = $this->seedProduct();
        $this->seedListed($productId, 'tt-990077');
        $starts = now()->addHours(2)->startOfMinute();
        $ends = now()->addDays(3)->startOfMinute();
        TikTokListing::create([
            'product_id' => $productId,
            'sale_percent' => 10, 'sale_starts_at' => $starts, 'sale_ends_at' => $ends,
        ]);
        $this->armHappyPath();

        $r = $this->actingAs($this->manager())
            ->post(route('ext.tiktok.listings.push_discount', $productId), ['action' => 'push']);

        $r->assertSessionHas('status');

        $creates = $this->callsFor('POST', '/promotion/202309/activities');
        $this->assertCount(1, $creates);
        $this->assertSame('DIRECT_DISCOUNT', $creates[0]['body']['activity_type']);
        $this->assertSame('PRODUCT', $creates[0]['body']['product_level']);
        $this->assertSame($starts->timestamp, $creates[0]['body']['begin_time']);
        $this->assertSame($ends->timestamp, $creates[0]['body']['end_time']);
        $this->assertStringStartsWith('VentaSync', $creates[0]['body']['title']);

        $joins = $this->callsFor('PUT', '/promotion/202309/activities/act-777/products');
        $this->assertCount(1, $joins);
        $row = $joins[0]['body']['products'][0];
        $this->assertSame('tt-990077', $row['id']);
        $this->assertSame('10', $row['discount']);
        $this->assertSame(-1, $row['quantity_limit']);

        $this->assertSame('act-777', TikTokListing::where('product_id', $productId)->value('tiktok_activity_id'));
    }

    public function test_two_products_on_the_same_window_share_one_activity(): void
    {
        $starts = now()->addHours(2)->startOfMinute();
        $ends = now()->addDays(3)->startOfMinute();

        // Product A already pushed this window and holds the activity.
        $productA = $this->seedProduct('A');
        $this->seedListed($productA, 'tt-990077');
        TikTokListing::create([
            'product_id' => $productA,
            'sale_percent' => 10, 'sale_starts_at' => $starts, 'sale_ends_at' => $ends,
            'tiktok_activity_id' => 'act-777',
        ]);

        // Product B saved the SAME window; its push must only JOIN.
        $productB = $this->seedProduct('B');
        $this->seedListed($productB, 'tt-990088');
        TikTokListing::create([
            'product_id' => $productB,
            'sale_percent' => 25, 'sale_starts_at' => $starts, 'sale_ends_at' => $ends,
        ]);
        $this->armHappyPath();

        $r = $this->actingAs($this->manager())
            ->post(route('ext.tiktok.listings.push_discount', $productB), ['action' => 'push']);

        $r->assertSessionHas('status');
        $this->assertCount(0, $this->callsFor('POST', '/promotion/202309/activities'));

        $joins = $this->callsFor('PUT', '/promotion/202309/activities/act-777/products');
        $this->assertCount(1, $joins);
        $this->assertSame('tt-990088', $joins[0]['body']['products'][0]['id']);
        $this->assertSame('25', $joins[0]['body']['products'][0]['discount']);

        $this->assertSame('act-777', TikTokListing::where('product_id', $productB)->value('tiktok_activity_id'));
    }

    public function test_end_removes_the_product_and_clears_the_rule(): void
    {
        $productId = $this->seedProduct();
        $this->seedListed($productId, 'tt-990077');
        TikTokListing::create([
            'product_id' => $productId,
            'sale_percent' => 10,
            'sale_starts_at' => now()->addHours(2), 'sale_ends_at' => now()->addDays(3),
            'tiktok_activity_id' => 'act-777',
        ]);
        $this->armHappyPath();

        $r = $this->actingAs($this->manager())
            ->post(route('ext.tiktok.listings.push_discount', $productId), ['action' => 'end']);

        $r->assertSessionHas('status');
        $removes = $this->callsFor('DELETE', '/promotion/202309/activities/act-777/products');
        $this->assertCount(1, $removes);
        $this->assertSame(['tt-990077'], $removes[0]['body']['product_ids']);

        $listing = TikTokListing::where('product_id', $productId)->first();
        $this->assertNull($listing->sale_percent);
        $this->assertNull($listing->sale_starts_at);
        $this->assertNull($listing->sale_ends_at);
        $this->assertNull($listing->tiktok_activity_id);
    }

    public function test_an_unlisted_product_is_refused_before_any_call(): void
    {
        $productId = $this->seedProduct();
        TikTokListing::create([
            'product_id' => $productId,
            'sale_percent' => 10,
            'sale_starts_at' => now()->addHours(2), 'sale_ends_at' => now()->addDays(3),
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.tiktok.listings.push_discount', $productId), ['action' => 'push']);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('not on TikTok Shop', session('error'));
        $this->assertSame([], $this->sentCalls);
    }

    public function test_push_without_a_saved_rule_is_refused_before_any_call(): void
    {
        $productId = $this->seedProduct();
        $this->seedListed($productId, 'tt-990077');

        $r = $this->actingAs($this->manager())
            ->post(route('ext.tiktok.listings.push_discount', $productId), ['action' => 'push']);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Set the discount first', session('error'));
        $this->assertSame([], $this->sentCalls);
    }

    public function test_a_start_already_passed_is_refused_in_plain_words(): void
    {
        $productId = $this->seedProduct();
        $this->seedListed($productId, 'tt-990077');
        TikTokListing::create([
            'product_id' => $productId,
            'sale_percent' => 10,
            'sale_starts_at' => now()->subMinutes(5), 'sale_ends_at' => now()->addDays(3),
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.tiktok.listings.push_discount', $productId), ['action' => 'push']);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('start time has passed', session('error'));
        $this->assertSame([], $this->sentCalls);
    }
}
