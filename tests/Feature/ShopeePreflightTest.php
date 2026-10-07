<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopeePreflightTest extends TestCase
{
    use RefreshDatabase;

    private array $calls = [];

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

        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            { $this->test->record($path); return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => []]]; }
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            { $this->test->record($path); return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['success_list' => []]]]; }
        };
        $this->app->instance(ShopeeClient::class, $fake);
    }

    public function record(string $path): void
    {
        $this->calls[] = $path;
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Preflight desk']);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $sku, float $weight, float $dims): int
    {
        $pfx = (string) config('catalog.prefix');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/x.png',
            'weight' => $weight, 'length' => $dims, 'width' => $dims, 'height' => $dims,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Preflight product ' . $sku, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_a_relist_that_shopee_would_refuse_is_refused_here_first(): void
    {
        $pid = $this->seedProduct('PF-1', 0, 0);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 700, 'live_status' => 'UNLIST']);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.toggle', $pid), ['action' => 'list']);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('a package weight', session('error'));
        $this->assertStringContainsString('Add them on the catalog product', session('error'));
        $this->assertSame([], $this->calls, 'the marketplace was never asked - that is the whole point');
    }

    public function test_a_ready_relist_still_goes_through(): void
    {
        $pid = $this->seedProduct('PF-2', 0.5, 10);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 701, 'live_status' => 'UNLIST']);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.toggle', $pid), ['action' => 'list']);

        $this->assertNotEmpty($this->calls, 'a preflight that blocks the valid case is worse than none');
    }

    public function test_a_bulk_relist_holds_back_the_refusable_rows_by_name(): void
    {
        $good = $this->seedProduct('PF-3', 0.5, 10);
        $bad = $this->seedProduct('PF-4', 0, 0);
        ShopeeProductLink::create(['product_id' => $good, 'shopee_item_id' => 702, 'live_status' => 'UNLIST']);
        ShopeeProductLink::create(['product_id' => $bad, 'shopee_item_id' => 703, 'live_status' => 'UNLIST']);

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.products.bulk_toggle'), [
            'action' => 'list', 'product_ids' => [$good, $bad],
        ]);

        $r->assertSessionHas('warning');
        $this->assertStringContainsString('held back', session('warning'));
        $this->assertStringContainsString("#{$bad} needs a package weight", session('warning'));
    }

    public function test_the_row_menu_offers_the_fix_instead_of_the_failure(): void
    {
        $pid = $this->seedProduct('PF-5', 0, 0);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 704, 'live_status' => 'UNLIST']);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index', ['q' => 'PF-5']))->assertOk()->getContent();

        $this->assertStringContainsString('Add weight and size, then publish', $html);
        $this->assertStringNotContainsString('x-menu__item">Publish on Shopee', $html,
            'a row whose publish would certainly be refused must not offer it');
    }

    public function test_a_push_update_without_a_parcel_is_refused_before_the_call(): void
    {
        $pid = $this->seedProduct('PF-6', 0, 5);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 705, 'live_status' => 'NORMAL']);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('a package weight', session('error'));
        $this->assertSame([], $this->calls);
    }
}
