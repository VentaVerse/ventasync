<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ShopeeReturnSolutionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');

        $this->app->register(ShopeeExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function userWith(array $permissionKeys): User
    {
        $group = UserGroup::create(['name' => 'Solutions '.implode('-', $permissionKeys)]);

        $group->permissions()->attach(
            Permission::whereIn('key', $permissionKeys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_a_read_only_operator_may_ask_what_can_be_offered(): void
    {
        $viewer = $this->userWith(['view_shopee/dashboard', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/order']);

        $response = $this->actingAs($viewer)
            ->getJson(route('ext.shopee.orders.return_solutions', ['returnSn' => '220106202649696']));

        $response->assertOk();
        $response->assertSessionMissing('error');
    }

    public function test_someone_with_no_shopee_permission_cannot_ask(): void
    {
        $outsider = $this->userWith(['view_catalog/manufacturer', 'view_catalog/category', 'view_api/catalog', 'view_catalog/product', 'view_catalog/product_image']);

        $this->actingAs($outsider)
            ->get(route('ext.shopee.orders.return_solutions', ['returnSn' => '220106202649696']))
            ->assertStatus(403)
            ->assertSee('You are not allowed to access this page');
    }

    public function test_no_connection_reports_the_reason_and_offers_nothing(): void
    {
        $viewer = $this->userWith(['view_shopee/dashboard', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/order']);

        $response = $this->actingAs($viewer)
            ->getJson(route('ext.shopee.orders.return_solutions', ['returnSn' => '220106202649696']));

        $response->assertOk();
        $response->assertJson(['ok' => false]);
        $response->assertJsonMissingPath('solutions');

        $this->assertNotEmpty(
            $response->json('message'),
            'a refusal must say why; an empty list would read as "nothing is on offer"'
        );
    }

    public function test_the_panel_only_draws_what_shopee_permits(): void
    {
        $js = File::get(resource_path('js/pages/shopee-orders.js'));

        $this->assertStringContainsString('return s.eligible;', $js,
            'only eligible solutions may be drawn');
        $this->assertStringContainsString('max_refund_amount !== null', $js,
            'a missing ceiling must not render as a number');

        $view = File::get(base_path('extensions/shopee/views/orders/return-detail.blade.php'));
        $this->assertStringContainsString('data-solutions-ask', $view);

        $this->assertStringContainsString('it does not submit', $js);
    }
}
