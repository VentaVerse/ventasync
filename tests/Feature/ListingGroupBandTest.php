<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Shopee\Models\ShopeeProductGroup;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListingGroupBandTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');

        ShopeeStore::query()->create(['mode' => 'live', 'partner_id' => '1', 'partner_key' => 'k', 'shop_id' => '2', 'access_token' => 't', 'refresh_token' => 'r']);
        $this->app->instance(ShopeeClient::class, new class extends ShopeeClient {
            public function __construct() {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                return ['ok' => true, 'status' => 200, 'body' => ['response' => []]];
            }
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                return ['ok' => false, 'status' => 500, 'body' => ['error' => 'error', 'message' => 'unexpected GET ' . $path]];
            }
        });
    }

    private function user(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Band ' . (++$this->seq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function product(): int
    {
        $pid = DB::table('products')->insertGetId([
            'sku' => 'BAND-1', 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'brand_id' => 0, 'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Band product', 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    public function test_the_band_lists_the_stores_groups_with_the_products_own_picked(): void
    {
        $pid = $this->product();
        $mine = ShopeeProductGroup::create(['name' => 'Multi-effects pedals']);
        ShopeeProductGroup::create(['name' => 'Overdrive pedals']);
        DB::table('ch_shopee_product_group_products')->insert(['shopee_product_group_id' => $mine->id, 'product_id' => $pid]);

        $html = $this->actingAs($this->user(['view_shopee/product', 'manage_shopee/product']))
            ->get(route('ext.shopee.listings.edit', $pid))
            ->assertOk()
            ->assertSee('Product group')
            ->assertSee('Overdrive pedals')
            ->assertSee("Show the group's products", false)
            ->assertSee('name="product_group_listed"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="' . $mine->id . '"\s+selected/', $html, "the product's own group is picked");
        $this->assertStringNotContainsString('100013', $html, 'a group carries no values to the listing page');
        $this->assertStringContainsString(e(route('ext.shopee.products.index', ['group' => $mine->id])), $html, 'the group opens as a filter on the Products page');
    }

    public function test_a_reader_sees_the_group_but_cannot_move_the_product(): void
    {
        $pid = $this->product();
        $mine = ShopeeProductGroup::create(['name' => 'Multi-effects pedals']);
        DB::table('ch_shopee_product_group_products')->insert(['shopee_product_group_id' => $mine->id, 'product_id' => $pid]);

        $this->actingAs($this->user(['view_shopee/product']))
            ->get(route('ext.shopee.listings.edit', $pid))
            ->assertOk()
            ->assertSee('Multi-effects pedals')
            ->assertDontSee('name="product_group_listed"', false);
    }
}
