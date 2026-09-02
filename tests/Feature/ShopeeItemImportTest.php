<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Models\ShopeeUnmatchedItem;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Import a Shopee item into the core catalog (#196). The owner's spec:
 * everything with a core field maps to the core; Shopee-only details
 * ride the product's listing record instead of being dropped; the new
 * product links so it behaves as listed at once.
 */
class ShopeeItemImportTest extends TestCase
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

        ShopeeSetting::create([
            'mode' => 'production', 'store_name' => 'Main store',
            'partner_id' => 1, 'partner_key' => 'k', 'shop_id' => 2,
            'access_token' => 't', 'refresh_token' => 'r',
        ]);

        Http::fake(['cf.shopee.ph/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $fake = new class extends ShopeeClient {
            public function __construct() {}
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                if (str_contains($path, 'get_item_base_info')) {
                    return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['item_list' => [[
                        'item_id' => 990001, 'item_status' => 'NORMAL', 'item_name' => 'Imported Overdrive Pedal',
                        'item_sku' => 'IMP-OD1', 'has_model' => true,
                        'description' => 'A warm overdrive.',
                        'weight' => 0.4, 'dimension' => ['package_length' => 12, 'package_width' => 8, 'package_height' => 6],
                        'price_info' => [['current_price' => 1500.0]],
                        'stock_info_v2' => ['summary_info' => ['total_available_stock' => 7]],
                        'image' => ['image_url_list' => ['https://cf.shopee.ph/img/main.jpg', 'https://cf.shopee.ph/img/side.jpg']],
                        'category_id' => 101988,
                        'brand' => ['brand_id' => 55],
                        'attribute_list' => [['attribute_id' => 1000, 'attribute_value_list' => [['original_value_name' => 'Alloy']]]],
                    ]]]]];
                }
                if (str_contains($path, 'get_model_list')) {
                    return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => [
                        'tier_variation' => [['name' => 'Voltage', 'option_list' => [['option' => '9V'], ['option' => '18V']]]],
                        'model' => [
                            ['model_id' => 71, 'model_sku' => 'IMP-OD1-9', 'tier_index' => [0], 'price_info' => [['current_price' => 1500.0]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 4]]],
                            ['model_id' => 72, 'model_sku' => 'IMP-OD1-18', 'tier_index' => [1], 'price_info' => [['current_price' => 1700.0]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 3]]],
                        ],
                    ]]];
                }
                return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => []]];
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Import desk']);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function queueRow(): ShopeeUnmatchedItem
    {
        return ShopeeUnmatchedItem::create([
            'shopee_item_id' => 990001, 'shopee_model_id' => 71,
            'item_name' => 'Imported Overdrive Pedal', 'sku' => 'IMP-OD1-9',
            'status' => 'unmatched',
        ]);
    }

    public function test_the_whole_item_lands_as_a_real_linked_product(): void
    {
        $row = $this->queueRow();

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.import_unmatched', $row->id));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('Imported "Imported Overdrive Pedal"', session('status'));
        $this->assertStringContainsString('2 variations', session('status'));

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->where('sku', 'IMP-OD1')->first();
        $this->assertNotNull($product);
        $this->assertSame(1500.0, (float) $product->price);
        $this->assertSame(0.4, (float) $product->weight, 'the parcel arrives - the exact fields every push needs');
        $this->assertSame(12.0, (float) $product->length);
        $this->assertSame(1, (int) $product->status, 'live on Shopee means live here - arriving disabled would misstate reality');
        $this->assertNotSame('', (string) $product->image, 'the main image was downloaded');

        $name = DB::table($pfx . 'product_description')->where('product_id', $product->product_id)->value('name');
        $this->assertSame('Imported Overdrive Pedal', $name);

        // Variations through the real writer: combination rows with their own SKUs and prices.
        $combos = DB::table('product_option_combinations')->where('product_id', $product->product_id)->get();
        $this->assertCount(2, $combos);
        $this->assertEqualsCanonicalizing(['IMP-OD1-9', 'IMP-OD1-18'], $combos->pluck('sku')->all());

        // Shopee-only details ride the listing record, not the floor.
        $listing = ShopeeListing::where('product_id', $product->product_id)->first();
        $this->assertSame(101988, (int) $listing->shopee_category_id);
        $this->assertSame('Alloy', (string) data_get($listing->attribute_values, '1000'));

        // Linked at once: parent + both models.
        $this->assertSame(3, ShopeeProductLink::where('product_id', $product->product_id)->count());

        $this->assertSame('linked', $row->fresh()->status, 'every queue row of the item clears together');
    }

    public function test_a_sku_collision_refuses_the_import_and_creates_nothing(): void
    {
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product')->insert([
            'model' => 'HOLDER', 'sku' => 'IMP-OD1-9', 'quantity' => 1, 'price' => 10,
            'status' => 1, 'image' => '', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        $row = $this->queueRow();

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.import_unmatched', $row->id));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Not imported', session('error'));
        $this->assertNull(DB::table($pfx . 'product')->where('sku', 'IMP-OD1')->first(),
            'a refused import must leave nothing half-created - the transaction rolls the product back');
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_the_queue_offers_import_and_the_old_vocabulary_is_gone(): void
    {
        $this->queueRow();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('ext.shopee.products.import_unmatched', ShopeeUnmatchedItem::query()->value('id')), $html);
        $this->assertStringContainsString('not in the catalog', $html);
        $this->assertStringNotContainsString('Unmatched items', $html, 'the concept is Import now; the old label retired with it');
        $this->assertStringNotContainsString('New listing</x-ui.button>', $html, 'the picker page retired - every unlisted row offers its own push');
    }
}
