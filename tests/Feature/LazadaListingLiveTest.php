<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lazada parity for the live listing surfaces (2026-08-24): the listings
 * index wears the seller center's tabs answered by /products/get on every
 * load, uploaded rows show Lazada's live status (or "Not found on Lazada"
 * when a successful answer omits them), and the edit page opens with the
 * item's live state from /product/item/get. When Lazada does not answer,
 * every surface says so instead of substituting stale data.
 */
class LazadaListingLiveTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake marketplace answers, keyed by api path. Closures get the params. */
    public array $getResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        LazadaSetting::query()->create([
            'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r',
        ]);

        $test = $this;
        $fake = new class($test) extends LazadaClient {
            public function __construct(private $test) {}
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $answer = $this->test->getResponses[$apiPath] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($params);
                }

                return $answer
                    ?? ['ok' => false, 'status' => 500, 'body' => ['code' => '500', 'message' => 'unexpected GET ' . $apiPath]];
            }
            public function sign(string $apiPath, array $params, string $appSecret): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(LazadaClient::class, $fake);
    }

    private int $groupSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada live group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'manage_lazada/product', 'view_lazada/product',
                'manage_lazada/product_group', 'view_lazada/product_group',
            ])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    /** /products/get answers: counts and ids per filter, plus sku lookups. */
    private function fakeProductsGet(array $idsByFilter, array $statusById = []): void
    {
        $this->getResponses['/products/get'] = function (array $params) use ($idsByFilter, $statusById) {
            if (!empty($params['sku_seller_list'])) {
                $products = [];
                foreach ($statusById as $id => $status) {
                    $products[] = ['item_id' => $id, 'status' => $status];
                }

                return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
                    'total_products' => count($products), 'products' => $products,
                ]]];
            }

            $ids = $idsByFilter[$params['filter'] ?? ''] ?? [];
            $products = array_map(fn ($i) => ['item_id' => $i, 'status' => 'x'], $ids);

            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
                'total_products' => count($ids),
                'products' => ((int) ($params['limit'] ?? 1)) === 1 ? array_slice($products, 0, 1) : $products,
            ]]];
        };
    }

    public function test_the_index_tabs_speak_lazadas_live_counts(): void
    {
        $this->fakeProductsGet([
            'live' => [111, 222], 'inactive' => [333], 'pending' => [],
            'rejected' => [], 'sold-out' => [], 'deleted' => [],
        ]);

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));

        $r->assertOk();
        $r->assertSee('Pending QC');
        $r->assertSee('lazada_tab=active', false);
    }

    public function test_a_live_tab_narrows_the_table_to_items_lazada_holds_in_that_status(): void
    {
        $inTab = $this->seedProduct('Active on Lazada product', 'LZT-IN');
        LazadaProduct::query()->create(['product_id' => $inTab, 'lazada_item_id' => '111']);
        $outOfTab = $this->seedProduct('Inactive elsewhere product', 'LZT-OUT');
        LazadaProduct::query()->create(['product_id' => $outOfTab, 'lazada_item_id' => '333']);

        $this->fakeProductsGet(
            ['live' => [111], 'inactive' => [333], 'pending' => [], 'rejected' => [], 'sold-out' => [], 'deleted' => []],
            [111 => 'active']
        );

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.index', ['lazada_tab' => 'active']));

        $r->assertOk();
        $r->assertSee('Active on Lazada product');
        $r->assertDontSee('Inactive elsewhere product');
    }

    public function test_an_uploaded_row_lazada_does_not_recognise_reads_not_found(): void
    {
        $productId = $this->seedProduct('Refresh residue product', 'LZT-GHOST');
        LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '424242']);

        // Counts succeed; the SKU lookup succeeds and names OTHER items only.
        $this->fakeProductsGet(
            ['live' => [111], 'inactive' => [], 'pending' => [], 'rejected' => [], 'sold-out' => [], 'deleted' => []],
            [111 => 'active']
        );

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));

        $r->assertOk();
        $r->assertSee('Not found on Lazada');
        $r->assertDontSee('>Uploaded<', false);
    }

    public function test_when_lazada_does_not_answer_the_tabs_step_aside_and_the_table_survives(): void
    {
        $productId = $this->seedProduct('Still visible product', 'LZT-KEEP');
        LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '111']);
        // No /products/get response registered: the fake answers ok=false.

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));

        $r->assertOk();
        $r->assertSee('Lazada did not answer');
        $r->assertSee('Still visible product');
        $r->assertDontSee('lazada_tab=active', false);
    }

    public function test_a_group_page_tells_the_products_truth_not_its_own_memory(): void
    {
        /* The one-truth port (2026-08-25), same disease the owner caught on
         * Shopee's GP-180: this group's push failed, then the product went
         * up elsewhere - the group page must read the LISTING record, with
         * the group's failed attempt named as exactly that. */
        $productId = $this->seedProduct('Truthful group product', 'LZT-TRUTH');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '990077',
            'last_pushed_at' => now()->subMinutes(5), 'last_push_source' => 'listing',
        ]);
        $group = \Extensions\lazada\Models\LazadaProductGroup::create([
            'name' => 'Truth group', 'lazada_category_id' => 12345,
        ]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'product_id' => $productId,
            'lazada_product_id' => $listing->id,
            'sync_status' => 'error', 'push_error' => 'Special characters are not allowed.',
            'last_pushed_at' => now()->subMinutes(37),
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.product-groups.products', $group->id));

        $r->assertOk();
        $r->assertSee('990077');
        $r->assertSee('Listed');
        $r->assertDontSee('>Failed<', false);
        $r->assertSee("This group's last push", false);
        $r->assertSee('Special characters');
        $r->assertSee('from its listing');
    }

    public function test_the_edit_page_opens_with_the_items_live_state(): void
    {
        $productId = $this->seedProduct('Live edit product', 'LZT-LIVE');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077']);

        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 990077, 'status' => 'active', 'subStatus' => '',
            'attributes' => ['name' => 'Live edit product'],
            'skus' => [[
                'SellerSku' => 'LZT-LIVE', 'price' => 450.0, 'special_price' => 0,
                'quantity' => 7, 'Status' => 'active',
            ]],
        ]]];

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.edit', $listing->id));

        $r->assertOk();
        $r->assertSee('On Lazada right now');
        $r->assertSee('LZT-LIVE');
    }

    public function test_the_edit_page_says_so_when_lazada_does_not_answer(): void
    {
        $productId = $this->seedProduct('Unreachable product', 'LZT-DOWN');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077']);
        // No /product/item/get response registered: the fake answers ok=false.

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.edit', $listing->id));

        $r->assertOk();
        $r->assertSee('Lazada did not answer');
    }
}
