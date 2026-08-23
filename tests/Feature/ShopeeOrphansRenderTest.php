<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Shopee orphan report renders, lists what it should, and writes nothing.
 *
 * Source-level tests pin the shape of the code; only rendering proves the page
 * exists, that its view compiles, and that the read tier can reach it. The
 * route was very nearly filed with the writes, which would have redirected the
 * operators most likely to use it.
 *
 * Faked at the HTTP boundary rather than left to reach Shopee: the seeded
 * credentials point nowhere, so a live run would spend the client's timeout
 * and its two retries failing, and would exercise the error path by accident
 * instead of the one under test.
 */
class ShopeeOrphansRenderTest extends TestCase
{
    use RefreshDatabase;

    // Extension routes are not registered in a bare test, so route() for any
    // ext.shopee.* name throws. Same boot ReadRouteDoesNotWriteTest performs.
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    // Permissions are keyed on `key`, and a user carries one group by
    // foreign key rather than a pivot. Copied from ReadRouteDoesNotWriteTest
    // rather than guessed at.
    private function user(array $permissions): User
    {
        $group = UserGroup::create(['name' => 'Shopee '.uniqid('', true)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $permissions)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    // Not seed(): that name is taken by Laravel's TestCase and PHP refuses
    // to lower its visibility.
    private function seedShop(): int
    {
        ShopeeSetting::create([
            'mode' => 'live',
            'partner_id' => 200123,
            'partner_key' => encrypt('live-partner-key'),
            'shop_id' => 900456,
            'access_token' => encrypt('live-access-token'),
            'region' => 'ph',
            'api_logging' => false,
        ]);

        return ShopeeProductGroup::create(['name' => 'Mosky'])->id;
    }

    /**
     * Item 5001 is linked, so it is accounted for. 5002 is not, so it is an
     * orphan. Its two SKUs are two variations of ONE item and must produce one
     * row, not two.
     */
    private function fakeShop(): void
    {
        Http::fake([
            '*get_item_list*' => Http::response([
                'response' => ['item' => [['item_id' => 5001], ['item_id' => 5002]], 'has_next_page' => false],
            ], 200),
            '*get_item_base_info*' => Http::response([
                'response' => ['item_list' => [
                    ['item_id' => 5001, 'item_sku' => 'KNOWN-1', 'item_status' => 'NORMAL', 'model_list' => []],
                    ['item_id' => 5002, 'item_sku' => 'STRANGER', 'item_status' => 'NORMAL', 'model_list' => [
                        ['model_sku' => 'STRANGER-RED'],
                        ['model_sku' => 'STRANGER-BLUE'],
                    ]],
                ]],
            ], 200),
        ]);
    }

    public function test_the_report_names_only_what_nothing_points_at(): void
    {
        $groupId = $this->seedShop();

        ShopeeProductLink::create(['product_id' => 4242, 'shopee_item_id' => 5001, 'sku' => 'known-1']);

        $this->fakeShop();

        $response = $this->actingAs($this->user(['view_shopee', 'view_shopee/dashboard', 'manage_shopee', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'manage_shopee/settings', 'manage_shopee/category', 'manage_shopee/category_attribute', 'manage_shopee/logistics', 'manage_shopee/product_group', 'manage_shopee/product', 'manage_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.orphans', $groupId))
            ->assertOk();

        $response->assertSee('5002')
            ->assertSee('stranger')
            ->assertDontSee('5001', false);
    }

    /** One row per item. Four variations are four SKUs on one listing. */
    public function test_variations_do_not_become_separate_rows(): void
    {
        $groupId = $this->seedShop();
        $this->fakeShop();

        $html = $this->actingAs($this->user(['view_shopee', 'view_shopee/dashboard', 'manage_shopee', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'manage_shopee/settings', 'manage_shopee/category', 'manage_shopee/category_attribute', 'manage_shopee/logistics', 'manage_shopee/product_group', 'manage_shopee/product', 'manage_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.orphans', $groupId))
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>5002<'),
            'One Shopee item appears more than once, so a product with variations reads as several '
            .'separate orphans.');
    }

    /**
     * The read tier reaches it. Noticing that the two sides have drifted is
     * exactly what a view-only operator does, and the page adopts nothing and
     * deletes nothing, so there is no reason to gate it behind manage.
     */
    public function test_the_read_tier_can_see_it(): void
    {
        $groupId = $this->seedShop();
        $this->fakeShop();

        $this->actingAs($this->user(['view_shopee', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.orphans', $groupId))
            ->assertOk();
    }

    /** And looking at it changes nothing in the database. */
    public function test_looking_at_the_report_writes_nothing(): void
    {
        $groupId = $this->seedShop();
        $this->fakeShop();

        $before = DB::table('shopee_product_links')->count();

        $this->actingAs($this->user(['view_shopee', 'view_shopee/dashboard', 'manage_shopee', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'manage_shopee/settings', 'manage_shopee/category', 'manage_shopee/category_attribute', 'manage_shopee/logistics', 'manage_shopee/product_group', 'manage_shopee/product', 'manage_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.orphans', $groupId))
            ->assertOk();

        $this->assertSame($before, DB::table('shopee_product_links')->count(),
            'Viewing the orphan report created or removed a link, so a read route is writing.');
    }
}
