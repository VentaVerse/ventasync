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

class ShopeeOrphansRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function user(array $permissions): User
    {
        $group = UserGroup::create(['name' => 'Shopee '.uniqid('', true)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $permissions)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedShop(): int
    {
        ShopeeSetting::create([
            'mode' => 'live',
            'partner_id' => 200123,
            'partner_key' => encrypt('live-partner-key'),
            'shop_id' => 900456,
            'access_token' => encrypt('live-access-token'),
            'region' => 'ph',
            'api_log_mode' => 'off',
        ]);

        return ShopeeProductGroup::create(['name' => 'Mosky'])->id;
    }

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

    public function test_the_read_tier_can_see_it(): void
    {
        $groupId = $this->seedShop();
        $this->fakeShop();

        $this->actingAs($this->user(['view_shopee', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.orphans', $groupId))
            ->assertOk();
    }

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
