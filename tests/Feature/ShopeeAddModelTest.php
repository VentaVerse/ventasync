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
 * Phase F of the channels rebuild (#184): adding a variation to a LIVE
 * Shopee listing is one push. The owner's daily loop - add the variation in
 * the ERP, unlink, re-match, hope - is retired: the tier structure is
 * extended by appending options (nothing live moves), add_model creates
 * the new combinations, and the links learn them at once. Anything that
 * cannot be done safely blind is refused in plain words before any
 * write.
 */
class ShopeeAddModelTest extends TestCase
{
    use RefreshDatabase;

    public array $postResponses = [];

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

                return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => []]];
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

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Add-model group ' . uniqid()]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** A product with one option axis (Pack size) and the given combinations. */
    private function packProduct(array $packs): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'PK', 'sku' => 'PK', 'quantity' => 30, 'price' => 100,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Pack product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        $optionId = (int) DB::table($pfx . 'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
        DB::table($pfx . 'option_description')->insert([
            'option_id' => $optionId, 'language_id' => $langId, 'name' => 'Pack size',
        ]);
        $productOptionId = (int) DB::table($pfx . 'product_option')->insertGetId([
            'product_id' => $productId, 'option_id' => $optionId, 'value' => '', 'required' => 0,
        ]);

        foreach ($packs as $i => [$name, $sku, $qty, $price]) {
            $valueId = (int) DB::table($pfx . 'option_value')->insertGetId([
                'option_id' => $optionId, 'image' => '', 'sort_order' => $i,
            ]);
            DB::table($pfx . 'option_value_description')->insert([
                'option_value_id' => $valueId, 'language_id' => $langId,
                'option_id' => $optionId, 'name' => $name,
            ]);
            $povId = (int) DB::table($pfx . 'product_option_value')->insertGetId([
                'product_option_id' => $productOptionId, 'product_id' => $productId,
                'option_id' => $optionId, 'option_value_id' => $valueId,
                'sku' => $sku, 'quantity' => $qty, 'subtract' => 1,
                'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
                'weight' => 0, 'weight_prefix' => '+',
                'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0,
                'absolute_cost' => 0, 'cost_prefix' => '+', 'absolute_price' => $price,
            ]);
            $comboId = (int) DB::table('product_option_combinations')->insertGetId([
                'product_id' => $productId, 'sku' => $sku, 'quantity' => $qty,
                'absolute_price' => $price, 'image' => null, 'status' => 1,
                'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_option_combination_values')->insert([
                'combination_id' => $comboId, 'product_option_value_id' => $povId,
            ]);
        }

        return $productId;
    }

    private function linkAndRule(int $productId): void
    {
        ShopeeProductLink::create(['product_id' => $productId, 'shopee_item_id' => 800, 'shopee_model_id' => 81, 'sku' => 'PK-5']);
        ShopeeListing::create(['product_id' => $productId, 'shopee_category_id' => 100139, 'markup_percent' => 0, 'markup_fixed' => 0]);
    }

    /** The Shopee side: one tier (Pack size) holding only "5 pcs" / PK-5 at first. */
    private function fakeShopeeHolds(): void
    {
        $calls = 0;
        $this->getResponses['/api/v2/product/get_model_list'] = function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return ['ok' => true, 'status' => 200, 'body' => ['response' => [
                    'tier_variation' => [['name' => 'Pack size', 'option_list' => [['option' => '5 pcs']]]],
                    'model' => [['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0], 'price_info' => [['original_price' => 500]]]],
                ]]];
            }

            // After add_model: the repair reads the new shape.
            return ['ok' => true, 'status' => 200, 'body' => ['response' => [
                'tier_variation' => [['name' => 'Pack size', 'option_list' => [['option' => '5 pcs'], ['option' => '10 pcs']]]],
                'model' => [
                    ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                    ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
                ],
            ]]];
        };
    }

    /** The loop, retired: a new pack size joins the live listing in one push. */
    public function test_a_new_variation_joins_the_live_listing_in_one_push(): void
    {
        $pid = $this->packProduct([['5 pcs', 'PK-5', 10, 500], ['10 pcs', 'PK-10', 8, 950]]);
        $this->linkAndRule($pid);
        $this->fakeShopeeHolds();

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_missing_variations', $pid))
            ->assertRedirect();

        $flash = (string) session('status');
        $this->assertStringContainsString('1 variation added to Shopee', $flash);
        $this->assertStringContainsString('1 new tier option', $flash);

        // The tier was EXTENDED, never rearranged: 5 pcs keeps position 0.
        $tier = collect($this->sentPosts)->firstWhere('path', '/api/v2/product/update_tier_variation');
        $this->assertNotNull($tier);
        $this->assertSame(['5 pcs', '10 pcs'], array_column($tier['body']['tier_variation'][0]['option_list'], 'option'));

        // add_model carries the new combination at the appended index,
        // priced by the listing's own rule.
        $add = collect($this->sentPosts)->firstWhere('path', '/api/v2/product/add_model');
        $this->assertNotNull($add);
        $this->assertSame('PK-10', $add['body']['model_list'][0]['model_sku']);
        $this->assertSame([1], $add['body']['model_list'][0]['tier_index']);
        $this->assertSame(950.0, (float) $add['body']['model_list'][0]['original_price']);

        // The links learned the new model at once.
        $models = ShopeeProductLink::query()->where('product_id', $pid)
            ->pluck('shopee_model_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame([81, 82], $models);
    }

    /** Nothing missing means nothing sent - and it says so. */
    public function test_nothing_missing_writes_nothing(): void
    {
        $pid = $this->packProduct([['5 pcs', 'PK-5', 10, 500]]);
        $this->linkAndRule($pid);
        $this->fakeShopeeHolds();

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_missing_variations', $pid))
            ->assertRedirect();

        $this->assertStringContainsString('already on Shopee', (string) session('status'));
        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/api/v2/product/update_tier_variation'));
        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/api/v2/product/add_model'));
    }

    /** A spread past Shopee's 5x rule - existing models included - is refused before any write. */
    public function test_a_price_spread_across_the_whole_item_is_refused_at_the_door(): void
    {
        $pid = $this->packProduct([['5 pcs', 'PK-5', 10, 500], ['100 pcs', 'PK-100', 3, 9000]]);
        $this->linkAndRule($pid);
        $this->fakeShopeeHolds();

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_missing_variations', $pid))
            ->assertRedirect();

        $this->assertStringContainsString('more than 5 times', (string) session('error'));
        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/api/v2/product/update_tier_variation'));
        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/api/v2/product/add_model'));
    }
}
