<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LazadaBulkPackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');

        $this->app->register(LazadaExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();

        \Extensions\lazada\Models\LazadaSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
    }

    private function userWith(array $permissionKeys): User
    {
        $group = UserGroup::create(['name' => 'Lazada Bulk '.implode('-', $permissionKeys)]);

        $group->permissions()->attach(
            Permission::whereIn('key', $permissionKeys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_a_read_only_operator_cannot_pack_a_selection(): void
    {
        $viewer = $this->userWith(['view_lazada/dashboard', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'view_lazada/order']);

        $this->actingAs($viewer)
            ->post(route('ext.lazada.orders.bulk_pack_print'), ['ids' => [1, 2, 3]])
            ->assertRedirect()
            ->assertSessionHas('error', "You don't have permission to do this action.");
    }

    public function test_a_read_only_operator_may_still_print_waybills(): void
    {
        $viewer = $this->userWith(['view_lazada/dashboard', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'view_lazada/order']);

        $response = $this->actingAs($viewer)
            ->post(route('ext.lazada.orders.bulk_awb'), ['ids' => [1, 2, 3]]);

        $response->assertStatus(422);
        $this->assertStringContainsString('AWB Error', $response->getContent());
        $response->assertSessionMissing('error');
    }

    public function test_someone_with_no_lazada_permission_reaches_neither(): void
    {
        $outsider = $this->userWith(['view_catalog/manufacturer', 'view_catalog/category', 'view_api/catalog', 'view_catalog/product', 'view_catalog/product_image']);

        $this->actingAs($outsider)
            ->post(route('ext.lazada.orders.bulk_pack_print'), ['ids' => [1]])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($outsider)
            ->post(route('ext.lazada.orders.bulk_awb'), ['ids' => [1]])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_more_than_fifty_orders_is_refused_with_an_instruction(): void
    {
        $manager = $this->userWith(['view_lazada/dashboard', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'manage_lazada/order']);

        $response = $this->actingAs($manager)
            ->from('/channels/lazada/orders')
            ->post(route('ext.lazada.orders.bulk_pack_print'), ['ids' => range(1, 51)]);

        $response->assertSessionHasErrors('ids');
        $this->assertStringContainsString(
            'at most 50',
            session('errors')->first('ids'),
            'the ceiling must tell the operator what to do, not just refuse'
        );
    }

    public function test_an_empty_selection_is_refused_before_anything_is_packed(): void
    {
        $manager = $this->userWith(['view_lazada/dashboard', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'manage_lazada/order']);

        $this->actingAs($manager)
            ->from('/channels/lazada/orders')
            ->post(route('ext.lazada.orders.bulk_pack_print'), ['ids' => []])
            ->assertSessionHasErrors('ids');

        $this->actingAs($manager)
            ->from('/channels/lazada/orders')
            ->post(route('ext.lazada.orders.bulk_pack_print'), [])
            ->assertSessionHasErrors('ids');
    }

    public function test_a_non_numeric_id_is_rejected_rather_than_queried(): void
    {
        $manager = $this->userWith(['view_lazada/dashboard', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'manage_lazada/order']);

        $this->actingAs($manager)
            ->from('/channels/lazada/orders')
            ->post(route('ext.lazada.orders.bulk_pack_print'), ['ids' => ['1; DROP TABLE orders']])
            ->assertSessionHasErrors('ids.0');
    }

    public function test_the_selection_posts_local_ids_not_marketplace_order_numbers(): void
    {
        $panel = file_get_contents(base_path('extensions/lazada/views/orders/_panel.blade.php'));

        $this->assertStringContainsString(
            'name="ids[]" value="{{ $o->id }}"',
            $panel,
            'the selection must post the local row id, so the marketplace id is resolved server-side'
        );
        $this->assertStringNotContainsString(
            'name="ids[]" value="{{ $orderId }}"',
            $panel
        );
    }
}
