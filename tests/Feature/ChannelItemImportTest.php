<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Models\LazadaUnmatchedItem;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Models\TikTokUnmatchedItem;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Lazada and TikTok halves of the importer (#196): the whole item
 * lands as a real core product, channel-only details ride the channel's
 * own listing record, and the link exists the moment it lands.
 */
class ChannelItemImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['lazada', 'tiktok'] as $ext) {
            $manager->install($ext);
            $manager->enable($ext);
        }
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        LazadaSetting::query()->create(['mode' => 'live', 'region' => 'ph', 'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't', 'refresh_token' => 'r']);
        TikTokSetting::create(['mode' => 'production', 'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3)]);

        Http::fake(['*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $lz = new class extends LazadaClient {
            public function __construct() {}
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                if ($apiPath === '/product/item/get') {
                    return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
                        'item_id' => 5551234,
                        'status' => 'active',
                        'primary_category' => 10099,
                        'attributes' => ['name' => 'Imported Cable Pack', 'description' => 'Braided cables.', 'brand' => 'SHA', 'material' => 'Nylon'],
                        'images' => ['https://ph-live.slatic.net/main.jpg'],
                        'skus' => [
                            ['SkuId' => 91, 'SellerSku' => 'LZI-1M', 'quantity' => 4, 'price' => 250, 'package_weight' => 0.2, 'package_length' => 20, 'package_width' => 10, 'package_height' => 3, 'saleProp' => ['Length' => '1m'], 'Status' => 'active', 'Images' => ['https://ph-live.slatic.net/1m.jpg']],
                            ['SkuId' => 92, 'SellerSku' => 'LZI-3M', 'quantity' => 6, 'price' => 450, 'special_price' => 399, 'saleProp' => ['Length' => '3m'], 'Status' => 'active'],
                        ],
                    ]]];
                }
                return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
            }
            public function sign(string $apiPath, array $params, string $appSecret): string { return 'sign'; }
        };
        $this->app->instance(LazadaClient::class, $lz);

        $tt = new class extends TikTokClient {
            public function __construct() {}
            public function get(string $appKey, string $appSecret, string $accessToken, string $path, array $extraParams = [], ?string $shopCipher = null): array
            {
                if (str_contains($path, '/products/tt-imp-1')) {
                    return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => [
                        'id' => 'tt-imp-1',
                        'status' => 'ACTIVATE',
                        'title' => 'Imported Strap',
                        'description' => '<p>Leather strap.</p>',
                        'category_chains' => [['id' => 'cat-1', 'is_leaf' => false], ['id' => 'cat-9', 'is_leaf' => true]],
                        'package_weight' => ['value' => '0.3'],
                        'package_dimensions' => ['length' => '30', 'width' => '10', 'height' => '4'],
                        'main_images' => [['urls' => ['https://p16.tiktokcdn.com/main.jpg']]],
                        'skus' => [
                            ['id' => 's-1', 'seller_sku' => 'TTI-BLK', 'price' => ['sale_price' => '700'], 'inventory' => [['quantity' => 5]], 'sales_attributes' => [['name' => 'Colour', 'value_name' => 'Black']]],
                            ['id' => 's-2', 'seller_sku' => 'TTI-TAN', 'price' => ['sale_price' => '750'], 'inventory' => [['quantity' => 2]], 'sales_attributes' => [['name' => 'Colour', 'value_name' => 'Tan']]],
                        ],
                    ]]];
                }
                return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => []]];
            }
            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            {
                if (str_contains($path, 'products/search')) {
                    return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['products' => [
                        ['id' => 'tt-imp-1', 'status' => 'ACTIVATE', 'title' => 'Imported Strap', 'skus' => [['seller_sku' => 'TTI-BLK'], ['seller_sku' => 'TTI-TAN']]],
                    ]]]];
                }
                return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => []]];
            }
        };
        $this->app->instance(TikTokClient::class, $tt);
    }

    private function manager(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Import parity ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_a_lazada_item_lands_whole_with_its_channel_details_riding_the_listing(): void
    {
        $row = LazadaUnmatchedItem::create(['lazada_item_id' => 5551234, 'item_name' => 'Imported Cable Pack', 'sku' => 'LZI-1M', 'status' => 'unmatched']);

        $r = $this->actingAs($this->manager(['manage_lazada/product', 'view_lazada/product']))
            ->post(route('ext.lazada.products.import_unmatched', $row->id));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('Imported "Imported Cable Pack"', session('status'));
        $this->assertStringContainsString('2 variations', session('status'));

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->where('model', 'like', 'LZD-%')->orWhere('quantity', 10)->orderByDesc('product_id')->first();
        $this->assertNotNull($product);
        $this->assertSame(10, (int) $product->quantity, 'quantity is the sum of the variations');
        $this->assertSame(0.2, (float) $product->weight, 'the parcel arrives from the first sku');

        $combos = DB::table('product_option_combinations')->where('product_id', $product->product_id)->get();
        $this->assertEqualsCanonicalizing(['LZI-1M', 'LZI-3M'], $combos->pluck('sku')->all());
        $this->assertSame(399.0, (float) $combos->firstWhere('sku', 'LZI-3M')->absolute_price, 'the special price wins where it exists');

        $listing = LazadaProduct::where('product_id', $product->product_id)->first();
        $this->assertSame('5551234', (string) $listing->lazada_item_id, 'linked the moment it lands');
        $this->assertSame(10099, (int) $listing->primary_category_id);
        $this->assertSame('Nylon', LazadaProductAttribute::where('lazada_product_id', $listing->id)->where('attribute_key', 'material')->value('value'),
            'a Lazada-only field the core has no column for rides the listing record, not the floor');

        $this->assertSame('linked', $row->fresh()->status);
    }

    public function test_a_tiktok_product_lands_whole_and_the_listing_row_is_the_link(): void
    {
        $row = TikTokUnmatchedItem::create(['tiktok_product_id' => 'tt-imp-1', 'item_name' => 'Imported Strap', 'sku' => 'TTI-BLK', 'status' => 'unmatched']);

        $r = $this->actingAs($this->manager(['manage_tiktok/product', 'view_tiktok/product']))
            ->post(route('ext.tiktok.products.import_unmatched', $row->id));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('Imported "Imported Strap"', session('status'));

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->orderByDesc('product_id')->first();
        $this->assertSame(7, (int) $product->quantity);
        $this->assertSame(0.3, (float) $product->weight);

        $combos = DB::table('product_option_combinations')->where('product_id', $product->product_id)->get();
        $this->assertEqualsCanonicalizing(['TTI-BLK', 'TTI-TAN'], $combos->pluck('sku')->all());

        $listing = TikTokListing::where('product_id', $product->product_id)->first();
        $this->assertSame('tt-imp-1', (string) $listing->tiktok_product_id);
        $this->assertSame('cat-9', (string) $listing->tiktok_category_id, 'the LEAF of the category chain, not the root');
        $this->assertSame('s-1', (string) data_get(json_decode((string) $listing->tiktok_sku_id, true), 'TTI-BLK'),
            'the seller_sku -> id map lands too - stock and price pushes work at once');

        $this->assertSame('linked', $row->fresh()->status);
    }

    public function test_the_tiktok_scan_queues_what_the_shop_holds_and_the_catalog_does_not(): void
    {
        $r = $this->actingAs($this->manager(['manage_tiktok/product', 'view_tiktok/product']))
            ->post(route('ext.tiktok.products.sync_unmatched'));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('not in the catalog', session('status'));
        $this->assertSame(2, TikTokUnmatchedItem::query()->where('status', 'unmatched')->count(), 'one row per seller SKU, like the other channels');

        $html = $this->actingAs($this->manager(['manage_tiktok/product', 'view_tiktok/product']))
            ->get(route('ext.tiktok.products.index'))->assertOk()->getContent();
        $this->assertStringContainsString('on TikTok Shop, not in the catalog', $html);
        $this->assertStringContainsString(route('ext.tiktok.products.import_unmatched', TikTokUnmatchedItem::query()->value('id')), $html);
    }
}
