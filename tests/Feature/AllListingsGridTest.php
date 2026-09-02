<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * All Listings (Phase D, #184): one grid, rows from the catalogue, one
 * column per channel implementing ListingStateSource. Core holds no
 * channel knowledge: every badge is a channel engine's own answer and
 * every link is the channel's own workbench URL, rendered verbatim.
 */
class AllListingsGridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada'] as $ext) {
            $manager->install($ext);
            $manager->enable($ext);
        }
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create(['mode' => 'live', 'partner_id' => '1', 'partner_key' => 'k', 'shop_id' => '2']);
        LazadaSetting::query()->create(['mode' => 'live', 'region' => 'ph', 'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't', 'refresh_token' => 'r']);
    }

    private function seedProduct(string $name, string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now()->subDays(30), 'date_modified' => now()->subDays(30), 'date_available' => now()->subDays(30),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Grid group ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function fullUser(): User
    {
        return $this->userWith(['view_shopee/dashboard', 'view_lazada/dashboard']);
    }

    /** One row, and each cell is that channel's own answer to the same product. */
    public function test_a_row_carries_each_channels_own_answer(): void
    {
        $pid = $this->seedProduct('Everywhere product', 'EV-1');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 7001, 'sku' => 'EV-1', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '7002', 'live_status' => 'rejected', 'live_checked_at' => now()]);

        $page = $this->actingAs($this->fullUser())->get(route('channels.listings'))->assertOk();

        $page->assertSee('Everywhere product');
        $page->assertSee('Shopee');
        $page->assertSee('Lazada');
        // Shopee says Live, Lazada says Rejected - the engines' own words.
        $page->assertSee('Live');
        $page->assertSee('Rejected');
        // Each cell links to that channel's own workbench.
        $page->assertSee('href="' . e(route('ext.shopee.listings.edit', $pid)) . '"', false);
        $lz = LazadaProduct::query()->where('product_id', $pid)->value('id');
        $page->assertSee('href="' . e(route('ext.lazada.products.edit', $lz)) . '"', false);
    }

    /** A product on no channel reads Not listed in every column, and links into set-up. */
    public function test_a_rowless_product_reads_not_listed_everywhere(): void
    {
        $pid = $this->seedProduct('Nowhere yet', 'NW-1');

        $page = $this->actingAs($this->fullUser())->get(route('channels.listings'))->assertOk();
        $page->assertSee('Nowhere yet');
        $page->assertSee('Not listed');
        // Lazada's link for a rowless product is the prefilled create form.
        $page->assertSee(e(route('ext.lazada.products.create', ['product_id' => $pid])), false);
    }

    /** A channel the operator may not see contributes no column - same gate as the hub. */
    public function test_a_channel_column_is_gated_by_the_cards_own_permission(): void
    {
        $pid = $this->seedProduct('Half visible', 'HV-1');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 7003, 'sku' => 'HV-1', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);

        $user = $this->userWith(['view_lazada/dashboard']);

        $page = $this->actingAs($user)->get(route('channels.listings'))->assertOk();
        $page->assertSee('Lazada');
        $page->assertDontSee('>Shopee<', false);
    }

    /**
     * The columns ARE the gate (the Integrations controllers' own-check
     * bargain): an operator with no visible channel sees the empty state
     * and NO catalogue data - not a 403 door, and not a product list.
     */
    public function test_no_visible_channel_means_no_catalogue_data(): void
    {
        $this->seedProduct('Secret product', 'SEC-1');

        $page = $this->actingAs($this->userWith([]))
            ->get(route('channels.listings'))
            ->assertOk();
        $page->assertSee('Nothing to show here yet');
        $page->assertDontSee('Secret product');
    }

    /** The search narrows the catalogue rows. */
    public function test_search_narrows_the_grid(): void
    {
        $this->seedProduct('Alpha guitar', 'AG-1');
        $this->seedProduct('Beta drum', 'BD-1');

        $page = $this->actingAs($this->fullUser())
            ->get(route('channels.listings', ['q' => 'Alpha']))->assertOk();
        $page->assertSee('Alpha guitar');
        $page->assertDontSee('Beta drum');
    }
}
