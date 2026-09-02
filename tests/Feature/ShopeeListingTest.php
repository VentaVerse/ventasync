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
 * The listing record (#97 phase 1) and the end of settings borrowing (#96).
 *
 * THE DEFECT THIS GUARDS AGAINST RETURNING: an individual push had no
 * settings of its own, so it borrowed the FIRST product group whose filter
 * matched - and with every live group's filters empty, primary-key order
 * chose the markup. It succeeded at the wrong price, silently. Now the push
 * form gives birth to the product's own listing record, one-click pushes
 * and price sync run on it, and a product without one is REFUSED by name,
 * never priced by accident.
 */
class ShopeeListingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{path: string, body: array}> */
    private array $sentPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        // Complete auth, plaintext: decrypted() falls back to plain values.
        ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);

        // Every marketplace call is captured, never sent. The image upload
        // path bypasses the client (raw Http facade), so it is faked at the
        // HTTP layer with the image_id shape push() reads.
        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->recordPayload($path, $body);

                return ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_id' => 990077]]];
            }
            public function signShop(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
            {
                return 'test-sign';
            }
            public function baseUrl(string $mode): string
            {
                return 'https://shopee.test';
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);

        \Illuminate\Support\Facades\Http::fake([
            'shopee.test/*' => \Illuminate\Support\Facades\Http::response([
                'response' => ['image_info' => ['image_id' => 'img-001']],
            ], 200),
        ]);

        // A real file for resolveLocalImagePath to find.
        @mkdir(public_path('image/catalog'), 0775, true);
        file_put_contents(public_path('image/catalog/__listing-test.png'), 'png');
    }

    protected function tearDown(): void
    {
        @unlink(public_path('image/catalog/__listing-test.png'));
        parent::tearDown();
    }

    public function recordPayload(string $path, array $body): void
    {
        $this->sentPayloads[] = ['path' => $path, 'body' => $body];
    }

    private int $managerSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Shopee listing managers ' . (++$this->managerSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product', 'manage_shopee/product_group', 'view_shopee/product_group'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(float $price = 300.0): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LST-1', 'sku' => 'LST-SKU-1', 'quantity' => 5, 'price' => $price,
            'status' => 1, 'image' => 'catalog/__listing-test.png', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);

        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Listing test product', 'description' => 'A perfectly real description.',
            'meta_title' => 'Listing test product', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    /** A catch-all group like all nine live ones: empty filters, loud markup. */
    private function seedCatchAllGroup(): ShopeeProductGroup
    {
        return ShopeeProductGroup::create([
            'name' => 'multi effects', 'catalog_category_ids' => [], 'manufacturer_ids' => [],
            'shopee_category_id' => 100013, 'logistic_ids' => [8003], 'markup_percent' => 50, 'markup_fixed' => 0,
        ]);
    }

    public function test_the_push_form_gives_birth_to_the_listing_record(): void
    {
        $productId = $this->seedProduct(300.0);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push.post', $productId), [
                'item_name' => 'Shopee-only name', 'description' => 'Shopee-only description.',
                'original_price' => 370.00, 'normal_stock' => 5, 'item_sku' => 'LST-SKU-1',
                'weight' => 0.5, 'package_length' => 10, 'package_width' => 10, 'package_height' => 5,
                'category_id' => 100200, 'logistic_ids' => [8003],
                'selected_images' => ['catalog/__listing-test.png'],
            ]);

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertNotNull($listing, 'The form push must create the listing record.');
        $this->assertSame(100200, (int) $listing->shopee_category_id);
        $this->assertSame([8003], $listing->logistic_ids);
        // The operator priced it at 370 over a 300 core: the rule stored is
        // core + 70, never the price itself (card #97).
        $this->assertSame(70.0, $listing->markup_fixed);
        $this->assertSame('form', $listing->last_push_source);
        $this->assertSame(370.0, (float) $listing->last_push_settings['price']);
        $this->assertSame('Shopee-only name', $listing->item_name);
    }

    public function test_one_click_push_refuses_a_product_with_no_listing_instead_of_borrowing(): void
    {
        $productId = $this->seedProduct();
        $this->seedCatchAllGroup(); // the trap: a catch-all with 50% markup exists

        $response = $this->actingAs($this->manager())
            ->from(route('ext.shopee.products.index'))
            ->post(route('ext.shopee.products.push_direct', $productId));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('no Shopee listing settings', session('error'));

        // NOTHING was sent to Shopee, and no link exists: the catch-all
        // group's 50% markup priced nothing.
        $this->assertSame([], $this->sentPayloads);
        $this->assertSame(0, ShopeeProductLink::query()->where('product_id', $productId)->count());
    }

    public function test_one_click_push_runs_on_the_listings_own_settings(): void
    {
        $productId = $this->seedProduct(300.0);
        $this->seedCatchAllGroup(); // must be ignored even though it exists

        ShopeeListing::create([
            'product_id' => $productId, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'markup_percent' => 0, 'markup_fixed' => 70,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.push_direct', $productId));

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The push must reach add_item.');
        // The LISTING's rule (300 + 70), never the catch-all group's 50% (450).
        $this->assertSame(370.0, (float) $add['body']['original_price']);
        $this->assertSame(100200, (int) $add['body']['category_id']);

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertSame(370.0, (float) $listing->last_push_settings['price']);
    }

    public function test_price_sync_skips_by_name_instead_of_borrowing_a_markup(): void
    {
        $productId = $this->seedProduct(300.0);
        $this->seedCatchAllGroup();

        ShopeeProductLink::create([
            'product_id' => $productId, 'shopee_item_id' => 555, 'shopee_model_id' => null, 'sku' => 'LST-SKU-1',
        ]);

        // No listing at all: the sync must refuse, naming the product.
        $response = $this->actingAs($this->manager())
            ->from(route('ext.shopee.products.index'))
            ->post(route('ext.shopee.products.sync_price', $productId));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('no listing price rule', session('error'));
        $this->assertSame([], collect($this->sentPayloads)->where('path', '/api/v2/product/update_price')->all());

        // With a rule, the sync prices by it.
        ShopeeListing::create([
            'product_id' => $productId, 'markup_percent' => 0, 'markup_fixed' => 70,
        ]);

        $this->actingAs($this->manager())->post(route('ext.shopee.products.sync_price', $productId));

        $update = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_price');
        $this->assertNotNull($update);
        $this->assertSame(370.0, (float) $update['body']['price_list'][0]['original_price']);
    }

    public function test_a_group_page_tells_the_products_truth_not_its_own_memory(): void
    {
        /* the owner's GP-180 (QAS, 2026-08-24): this group's push failed on
         * couriers, then the product went up through its listing - and the
         * group page kept saying "Not pushed / Failed" while the Listings
         * page said Live, because it read its own pivot copy. The page now
         * reads the product's record: Listed, with the group's failed
         * ATTEMPT named as exactly that. */
        $productId = $this->seedProduct();
        $group = $this->seedCatchAllGroup();
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
            'sync_status' => 'error',
            'push_error' => 'At least one shipping channel must be enabled for the product.',
            'last_pushed_at' => now()->subMinutes(37),
        ]);
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LST-SKU-1',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'last_pushed_at' => now()->subMinutes(5), 'last_push_source' => 'listing',
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.product-groups.products', $group->id));

        $r->assertOk();
        $r->assertSee('990077');
        $r->assertSee('Listed');
        $r->assertDontSee('>Failed<', false);
        $r->assertDontSee('Not pushed');
        // The group's own attempt is still told, scoped in words.
        $r->assertSee("This preset's last push", false);
        $r->assertSee('shipping channel');
        $r->assertSee('from its listing');
    }

    public function test_a_group_push_stamps_provenance_but_never_the_listings_own_settings(): void
    {
        $productId = $this->seedProduct(300.0);
        $group = $this->seedCatchAllGroup();
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.push', $group->id), ['ids' => [$productId]]);

        /* THE REGRESSION THIS PINS (found by the owner on QAS, 2026-08-24):
         * createOnShopee was extracted out of push() and left the
         * $logisticIds definition behind, so every group push sent
         * "logistic_info": [] and Shopee refused with
         * product.error_invalid_logistic_info. The group's couriers must
         * ride the payload. */
        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'The group push must reach add_item.');
        $this->assertSame(
            [['logistic_id' => 8003, 'enabled' => true]],
            $add['body']['logistic_info'],
            'The GROUP\'s couriers go with a group push.'
        );

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertNotNull($listing, 'A group push must leave an auditable provenance row. ');
        $this->assertSame('group:multi effects', $listing->last_push_source);
        $this->assertSame(50.0, (float) $listing->last_push_settings['markup_percent']);

        // And the listing's OWN settings stay untouched: a group push is not
        // an edit of the listing (the owner's settled rule, card #104).
        $this->assertNull($listing->shopee_category_id);
        $this->assertNull($listing->markup_fixed);
        $this->assertNull($listing->item_name);
    }
}
