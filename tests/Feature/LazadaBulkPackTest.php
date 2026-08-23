<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Packing a selection is the only bulk action on this surface that WRITES to
 * a marketplace, and packing cannot be undone from here. These are the guards
 * that matter for that: who may call it, how many at once, and what happens
 * when the selection is empty or stale.
 *
 * The happy path is not covered here on purpose - it makes live Lazada calls
 * per order, and a test that mocks the whole signed-call layer would be
 * asserting the mock. What IS asserted is everything that must hold BEFORE
 * the first call goes out, which is where the damage would be done.
 */
class LazadaBulkPackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Same idempotent-install rationale as FulfilmentHubTest::setUp():
     * install()/enable() alone do not re-register the provider once
     * ExtensionServiceProvider::boot() has run, and refreshNameLookups() is
     * needed because routes registered by a provider booted this late are
     * invisible to route() until the name table is rebuilt.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');

        $this->app->register(LazadaExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function userWith(array $permissionKeys): User
    {
        $group = UserGroup::create(['name' => 'Lazada Bulk '.implode('-', $permissionKeys)]);

        $group->permissions()->attach(
            Permission::whereIn('key', $permissionKeys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * The read tier may print a waybill for orders already on file - the same
     * call the single-order AWB already allows a viewer to make - but it may
     * NOT pack, because packing pushes to Lazada.
     *
     * Denial is a redirect carrying a flashed message, not a 403: see
     * EnsurePermission::deny(), which answers 403 only to JSON callers and
     * bounces HTML ones back to the form with session('error') so the person
     * sees why in context. Asserting 403 here would be asserting a contract
     * this application does not have.
     */
    public function test_a_read_only_operator_cannot_pack_a_selection(): void
    {
        $viewer = $this->userWith(['view_lazada', 'view_lazada/dashboard', 'view_lazada_orders']);

        $this->actingAs($viewer)
            ->post(route('ext.lazada.orders.bulk_pack_print'), ['ids' => [1, 2, 3]])
            ->assertRedirect()
            ->assertSessionHas('error', "You don't have permission to do this action.");
    }

    /**
     * The same viewer reaches the print route. It fails afterwards on missing
     * credentials in the test environment - 422 from awbError(), the AWB
     * path's own error page - which is a different refusal from being denied
     * the route, and that difference is the point of this test.
     */
    public function test_a_read_only_operator_may_still_print_waybills(): void
    {
        $viewer = $this->userWith(['view_lazada', 'view_lazada/dashboard', 'view_lazada_orders']);

        $response = $this->actingAs($viewer)
            ->post(route('ext.lazada.orders.bulk_awb'), ['ids' => [1, 2, 3]]);

        $response->assertStatus(422);
        $this->assertStringContainsString('AWB Error', $response->getContent());
        $response->assertSessionMissing('error');
    }

    public function test_someone_with_no_lazada_permission_reaches_neither(): void
    {
        $outsider = $this->userWith(['view_products']);

        $this->actingAs($outsider)
            ->post(route('ext.lazada.orders.bulk_pack_print'), ['ids' => [1]])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($outsider)
            ->post(route('ext.lazada.orders.bulk_awb'), ['ids' => [1]])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * A ceiling that is stated rather than silent. Fifty is the largest page
     * the list offers, so a selection cannot legitimately exceed it, and a
     * request that does is refused with a sentence that says what to do.
     */
    public function test_more_than_fifty_orders_is_refused_with_an_instruction(): void
    {
        $manager = $this->userWith(['view_lazada', 'view_lazada/dashboard', 'manage_lazada_orders']);

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
        $manager = $this->userWith(['view_lazada', 'view_lazada/dashboard', 'manage_lazada_orders']);

        $this->actingAs($manager)
            ->from('/channels/lazada/orders')
            ->post(route('ext.lazada.orders.bulk_pack_print'), ['ids' => []])
            ->assertSessionHasErrors('ids');

        $this->actingAs($manager)
            ->from('/channels/lazada/orders')
            ->post(route('ext.lazada.orders.bulk_pack_print'), [])
            ->assertSessionHasErrors('ids');
    }

    /**
     * Ids arrive from a checkbox in a page the operator did not write, so
     * they are validated as integers rather than trusted. A string id is a
     * malformed request, not a lookup that returns nothing.
     */
    public function test_a_non_numeric_id_is_rejected_rather_than_queried(): void
    {
        $manager = $this->userWith(['view_lazada', 'view_lazada/dashboard', 'manage_lazada_orders']);

        $this->actingAs($manager)
            ->from('/channels/lazada/orders')
            ->post(route('ext.lazada.orders.bulk_pack_print'), ['ids' => ['1; DROP TABLE orders']])
            ->assertSessionHasErrors('ids.0');
    }

    /**
     * The checkbox carries the LOCAL row id, never the marketplace order
     * number. A page that posted marketplace ids would let anyone pack any
     * order in the account by editing the form.
     */
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
