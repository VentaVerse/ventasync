<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\Listings\ListingVariations;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Services\Catalog\ProductDeleter;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\Shopee\ShopeeVariationPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListingVariationsTest extends TestCase
{
    use RefreshDatabase;

    public array $sentPosts = [];

    private int $seq = 0;

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
        $this->app->instance(ShopeeClient::class, new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->sentPosts[] = ['path' => $path, 'body' => $body];

                return ['ok' => true, 'status' => 200, 'body' => ['response' => []]];
            }
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                return ['ok' => false, 'status' => 500, 'body' => ['error' => 'error', 'message' => 'unexpected GET ' . $path]];
            }
        });
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Listing variations ' . (++$this->seq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function storeId(): int
    {
        return (int) ShopeeSetting::defaultStore()->id;
    }

    private function productWithCombinations(array $combos): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => 'PAR', 'sku' => 'PAR', 'quantity' => 10, 'price' => 100,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Switchable product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        foreach (array_keys($combos) as $i => $sku) {
            $this->addCombination($pid, $sku, $combos[$sku][0], $combos[$sku][1], $i);
        }

        return $pid;
    }

    private function addCombination(int $pid, string $sku, float $price, int $status, int $sort): void
    {
        DB::table('product_option_combinations')->insert([
            'product_id' => $pid, 'sku' => $sku, 'status' => $status, 'quantity' => 5,
            'absolute_price' => $price, 'absolute_cost' => 0, 'cost_amount' => 0, 'cost_additional' => 0,
            'subtract' => 1, 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_listing_page_lists_a_variation_the_moment_the_catalog_saves_it(): void
    {
        $pid = $this->productWithCombinations(['VAR-A' => [100, 1], 'VAR-B' => [100, 1]]);
        ShopeeListing::query()->create(['product_id' => $pid]);
        $user = $this->manager();

        $this->actingAs($user)->get(route('ext.shopee.listings.edit', $pid))
            ->assertOk()
            ->assertSee('Variations on Shopee')
            ->assertSee('VAR-A')
            ->assertSee('VAR-B');

        $this->addCombination($pid, 'VAR-C', 100, 1, 2);

        $this->actingAs($user)->get(route('ext.shopee.listings.edit', $pid))
            ->assertOk()
            ->assertSee('VAR-C');
    }

    public function test_the_parent_sku_a_store_row_carries_is_never_listed_as_a_variation(): void
    {
        $pid = $this->productWithCombinations(['VAR-A' => [100, 1]]);

        $card = ListingVariations::cardData('lazada', $this->storeId(), $pid, ['PAR', 'VAR-A', 'ODD-1']);

        $this->assertSame(['VAR-A'], array_column($card['variationRows'], 'sku'));
        $this->assertTrue($card['variationRows'][0]['onStore']);
        $this->assertSame(['ODD-1'], $card['variationExtras'], 'the parent SKU is not a stranger variation; a real one still is');
    }

    public function test_a_variation_switched_off_in_the_catalog_is_neither_listed_nor_sold(): void
    {
        $pid = $this->productWithCombinations(['VAR-A' => [100, 1], 'VAR-B' => [100, 0]]);

        $rows = ListingVariations::cardData('shopee', $this->storeId(), $pid, null)['variationRows'];
        $this->assertSame(['VAR-A'], array_column($rows, 'sku'));
        $this->assertSame(['VAR-A'], ListingVariations::sold('shopee', $this->storeId(), [$pid])[$pid]);
    }

    public function test_the_listing_save_keeps_the_stores_switches(): void
    {
        $pid = $this->productWithCombinations(['VAR-A' => [100, 1], 'VAR-B' => [100, 1]]);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $pid), [
            'variations_listed' => '1', 'variations_on' => ['VAR-A'],
        ])->assertRedirect();

        $this->assertSame(['VAR-A'], ListingVariations::sold('shopee', $this->storeId(), [$pid])[$pid]);

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $pid), [])->assertRedirect();
        $this->assertSame(['VAR-A'], ListingVariations::sold('shopee', $this->storeId(), [$pid])[$pid]);

        $this->assertSame(['VAR-A', 'VAR-B'], ListingVariations::sold('lazada', $this->storeId(), [$pid])[$pid]);
    }

    public function test_switching_the_dear_variation_off_for_shopee_answers_the_price_spread(): void
    {
        $pid = $this->productWithCombinations(['CHEAP' => [100, 1], 'DEAR' => [1000, 1]]);
        $push = app(ShopeeVariationPush::class);

        $this->assertNotNull($push->refusalFor($pid, fn (float $p) => $p, $this->storeId()));

        ListingVariations::save('shopee', $this->storeId(), $pid, ['CHEAP']);

        $this->assertNull($push->refusalFor($pid, fn (float $p) => $p, $this->storeId()));
    }

    public function test_the_stock_push_sends_only_what_the_store_sells(): void
    {
        $pid = $this->productWithCombinations(['VAR-A' => [100, 1], 'VAR-B' => [100, 1]]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 900, 'shopee_model_id' => 1, 'sku' => 'VAR-A']);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 900, 'shopee_model_id' => 2, 'sku' => 'VAR-B']);
        ListingVariations::save('shopee', $this->storeId(), $pid, ['VAR-A']);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.sync_quantity', $pid))
            ->assertRedirect();

        $this->assertNull(session('error'));
        $calls = array_values(array_filter($this->sentPosts, fn ($c) => $c['path'] === '/api/v2/product/update_stock'));
        $this->assertCount(1, $calls);
        $this->assertSame([1], array_column($calls[0]['body']['stock_list'], 'model_id'));
    }

    public function test_a_deleted_product_or_variation_leaves_no_switch_behind(): void
    {
        $pid = $this->productWithCombinations(['VAR-A' => [100, 1], 'VAR-B' => [100, 1]]);
        ListingVariations::save('shopee', $this->storeId(), $pid, []);
        $this->assertSame(2, DB::table(ListingVariations::TABLE)->where('product_id', $pid)->count());

        ListingVariations::forget($pid, ['VAR-B']);
        $this->assertSame(1, DB::table(ListingVariations::TABLE)->where('product_id', $pid)->count());

        ProductDeleter::forgetOwnedRows((string) config('catalog.prefix'), $pid);
        $this->assertSame(0, DB::table(ListingVariations::TABLE)->where('product_id', $pid)->count());
    }
}
