<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Core has changed" (#100), in the override model's grammar. A catalog
 * edit reaches no marketplace on its own; the surfaces where pushing
 * happens say so instead:
 *
 *  - the listing page's live section notes when the catalog moved AFTER
 *    the last push, pointing at Push update;
 *  - a field holding its own wording says inline when the catalog now
 *    differs beneath it - clearing is the accept gesture, keeping it is
 *    a decision, and NOTHING ever overwrites the operator's wording;
 *  - the group page's row marks "Catalog changed since this push".
 */
class ShopeeCoreChangedTest extends TestCase
{
    use RefreshDatabase;

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

        $fake = new class extends ShopeeClient {
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                if ($path === '/api/v2/product/get_item_base_info') {
                    return ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => [[
                        'item_id' => 990077, 'item_status' => 'NORMAL',
                        'item_name' => 'Core changed product', 'item_sku' => 'CC-SKU',
                        'has_model' => false, 'has_promotion' => false,
                        'price_info' => [['original_price' => 300.0, 'current_price' => 300.0]],
                        'stock_info_v2' => ['summary_info' => ['total_available_stock' => 5]],
                    ]]]]];
                }

                return ['ok' => false, 'status' => 500, 'body' => ['message' => 'not faked']];
            }
            public function signShop(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Core watchers ' . uniqid('', true)]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'manage_shopee/product', 'view_shopee/product',
                'manage_shopee/product_group', 'view_shopee/product_group',
            ])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(\DateTimeInterface $modifiedAt): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'CC-1', 'sku' => 'CC-SKU', 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0,
            'date_added' => now()->subDays(30), 'date_modified' => $modifiedAt, 'date_available' => now()->subDays(30),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Core changed product', 'description' => 'The catalog wording.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_the_listing_page_notes_a_catalog_change_after_the_last_push(): void
    {
        $productId = $this->seedProduct(now()->subHour());
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'CC-SKU',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'last_pushed_at' => now()->subDays(3), 'last_push_source' => 'listing',
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('The catalog product changed');
        $r->assertSee('after the last push');
    }

    public function test_no_note_when_the_push_is_newer_than_the_catalog(): void
    {
        $productId = $this->seedProduct(now()->subDays(3));
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'CC-SKU',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'last_pushed_at' => now()->subHour(), 'last_push_source' => 'listing',
        ]);

        $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId))
            ->assertDontSee('The catalog product changed');
    }

    public function test_an_overridden_field_says_when_the_catalog_now_differs(): void
    {
        $productId = $this->seedProduct(now()->subHour());
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'item_name' => 'My Shopee-safe rewrite',
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('The catalog now says');
        $r->assertSee('Core changed product');
        // The operator's wording is untouched in the field itself.
        $r->assertSee('My Shopee-safe rewrite');
    }

    public function test_the_group_row_marks_a_catalog_change_since_the_push(): void
    {
        $productId = $this->seedProduct(now()->subHour());
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'CC-SKU',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'last_pushed_at' => now()->subDays(3), 'last_push_source' => 'listing',
        ]);
        $group = ShopeeProductGroup::create([
            'name' => 'Core watch group',
            'shopee_category_id' => 100013, 'logistic_ids' => [41003],
        ]);
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.product-groups.products', $group->id));

        $r->assertOk();
        // The shared Last sync cell (2026-09-05) says the catalog moved after
        // the last successful sync, on the group page and the Listings page alike.
        $r->assertSee('catalog changed since');
    }
}
