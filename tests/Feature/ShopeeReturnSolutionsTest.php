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

/**
 * Asking Shopee what may be offered on a return.
 *
 * This is the first piece of the returns write flow and it is deliberately a
 * READ. Our sandbox refuses the write permissions, so the resolutions
 * themselves cannot be exercised before they are live - and a resolution that
 * fails does so in front of a customer. get_available_solutions is what lets
 * the interface offer only choices Shopee will accept, and it can be verified
 * against a live shop without pushing anything.
 *
 * The happy path is not covered here: it makes a live marketplace call, and a
 * test that mocked the signed-call layer would be asserting the mock. What is
 * covered is the tier it sits in and the way it behaves when the answer does
 * not arrive - which is the part that decides whether a wrong button is drawn.
 */
class ShopeeReturnSolutionsTest extends TestCase
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

    private function userWith(array $permissionKeys): User
    {
        $group = UserGroup::create(['name' => 'Solutions '.implode('-', $permissionKeys)]);

        $group->permissions()->attach(
            Permission::whereIn('key', $permissionKeys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * A read-only operator may ask. The call pushes nothing to Shopee, so
     * gating it behind the manage tier would deny customer service the one
     * thing that tells them what they are allowed to do.
     */
    public function test_a_read_only_operator_may_ask_what_can_be_offered(): void
    {
        $viewer = $this->userWith(['view_shopee', 'view_shopee/dashboard', 'view_shopee_orders', 'view_shopee/overview', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/order']);

        $response = $this->actingAs($viewer)
            ->getJson(route('ext.shopee.orders.return_solutions', ['returnSn' => '220106202649696']));

        $response->assertOk();
        $response->assertSessionMissing('error');
    }

    public function test_someone_with_no_shopee_permission_cannot_ask(): void
    {
        $outsider = $this->userWith(['view_products', 'view_catalog/manufacturer', 'view_catalog/category', 'view_api/catalog', 'view_catalog/product', 'view_catalog/product_image']);

        $this->actingAs($outsider)
            ->get(route('ext.shopee.orders.return_solutions', ['returnSn' => '220106202649696']))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * IT FAILS CLOSED, which is the whole point of the endpoint.
     *
     * With no connection there is no answer, and no answer must not render as
     * "no options available" - that reads as a settled fact about the return
     * when it is a fact about us. The response says so and carries no
     * solutions at all, so nothing downstream can draw a button from it.
     */
    public function test_no_connection_reports_the_reason_and_offers_nothing(): void
    {
        $viewer = $this->userWith(['view_shopee', 'view_shopee/dashboard', 'view_shopee_orders', 'view_shopee/overview', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/order']);

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

    /**
     * The interface never claims a resolution is open without Shopee saying
     * so, and never prints a ceiling it was not given. max_refund_amount is
     * documented as present only when the amount is adjustable, so a template
     * that reads it unconditionally would render a cap of zero.
     */
    public function test_the_panel_only_draws_what_shopee_permits(): void
    {
        $js = File::get(resource_path('js/pages/shopee-orders.js'));

        $this->assertStringContainsString('return s.eligible;', $js,
            'only eligible solutions may be drawn');
        $this->assertStringContainsString('max_refund_amount !== null', $js,
            'a missing ceiling must not render as a number');

        $view = File::get(base_path('extensions/shopee/views/orders/return-detail.blade.php'));
        $this->assertStringContainsString('data-solutions-ask', $view);

        // Nothing on this surface submits a resolution yet, and the copy says
        // so. If a write is added later, this assertion should fail and be
        // replaced deliberately rather than quietly outgrown.
        $this->assertStringContainsString('it does not submit', $js);
    }
}
