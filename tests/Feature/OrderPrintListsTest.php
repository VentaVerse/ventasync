<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\User;
use App\Models\Admin\UserGroup;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeOrderProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPrintListsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $manager = $this->app->make(\App\Extensions\ExtensionManager::class);

        foreach (['shopee', 'lazada'] as $extension) {
            $manager->install($extension);
            $manager->enable($extension);
        }

        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\lazada\LazadaExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Print '.implode('-', $keys)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedOrders(): array
    {
        $ids = [];

        foreach ([['A-1', 'QB-IC50', 2, 'Instrument cable'], ['A-2', 'QB-IC50', 3, 'Instrument cable'], ['A-3', 'EMP-EQ', 1, 'Equaliser pedal']] as [$sn, $sku, $qty, $name]) {
            $order = ShopeeOrder::create([
                'region' => 'ph',
                'order_sn' => $sn,
                'status' => 'READY_TO_SHIP',
                'raw' => ['buyer_username' => 'buyer_'.$sn],
            ]);

            ShopeeOrderProduct::create([
                'shopee_order_id' => $order->id,
                'sku' => $sku,
                'name' => $name,
                'quantity' => $qty,
            ]);

            $ids[] = $order->id;
        }

        return $ids;
    }

    public function test_a_pick_list_consolidates_one_sku_across_the_selection(): void
    {
        $ids = $this->seedOrders();

        $response = $this->actingAs($this->userWith(['view_shopee/order']))
            ->post(route('fulfilment.print.pick', ['channel' => 'shopee']), ['ids' => $ids]);

        $response->assertOk();

        $response->assertSee('QB-IC50');
        $response->assertSee('EMP-EQ');
        $response->assertSee('>5<', false);
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'QB-IC50'),
            'QB-IC50 was printed more than once - the pick list did not consolidate it.'
        );

        $response->assertSee('A-1');
        $response->assertSee('A-2');
    }

    public function test_a_packing_list_is_one_sheet_per_order_and_consolidates_nothing(): void
    {
        $ids = $this->seedOrders();

        $response = $this->actingAs($this->userWith(['view_shopee/order']))
            ->post(route('fulfilment.print.packing', ['channel' => 'shopee']), ['ids' => $ids]);

        $response->assertOk();

        $this->assertSame(
            3,
            substr_count($response->getContent(), 'class="pl-page"'),
            'A packing list must render one sheet per order.'
        );

        $this->assertSame(
            2,
            substr_count($response->getContent(), 'QB-IC50'),
            'The packing list merged a SKU across two orders. Each sheet travels in its own box.'
        );
    }

    public function test_the_view_tier_may_print_and_a_stranger_may_not(): void
    {
        $ids = $this->seedOrders();

        $this->actingAs($this->userWith(['view_shopee/order']))
            ->post(route('fulfilment.print.pick', ['channel' => 'shopee']), ['ids' => $ids])
            ->assertOk();

        $this->actingAs($this->userWith(['view_lazada/order']))
            ->post(route('fulfilment.print.pick', ['channel' => 'shopee']), ['ids' => $ids])
            ->assertForbidden();

    }

    public function test_a_signed_out_visitor_is_sent_to_sign_in(): void
    {
        $ids = $this->seedOrders();

        $this->post(route('fulfilment.print.pick', ['channel' => 'shopee']), ['ids' => $ids])
            ->assertRedirect(route('login'));
    }

    public function test_an_unknown_channel_is_not_a_channel(): void
    {
        $this->actingAs($this->userWith(['view_shopee/order']))
            ->post(route('fulfilment.print.pick', ['channel' => 'nosuchchannel']), ['ids' => [1]])
            ->assertNotFound();
    }

    public function test_a_selection_that_no_longer_exists_says_so_rather_than_printing_nothing(): void
    {
        $response = $this->actingAs($this->userWith(['view_shopee/order']))
            ->post(route('fulfilment.print.pick', ['channel' => 'shopee']), ['ids' => [999111, 999112]]);

        $response->assertSessionHasErrors('ids');
    }

    public function test_the_selection_is_capped_so_a_print_cannot_become_a_report(): void
    {
        $ids = range(1, 500);

        $orders = \App\Support\Fulfilment\OrderPrintLists::orders('shopee', $ids);

        $this->assertLessThanOrEqual(200, $orders->count());
    }
}
