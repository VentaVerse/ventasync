<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderControllerCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private string $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = (string) config('catalog.prefix');
    }

    private function actingAsOrderManager(): User
    {
        $group = UserGroup::create(['name' => 'Order Managers']);
        $permission = Permission::where('key', 'manage_sales/order')->firstOrFail();
        $group->permissions()->attach($permission->id);

        $user = User::factory()->create(['user_group_id' => $group->id]);
        $this->actingAs($user);

        return $user;
    }

    private function baseOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'order_status_id' => 1,
            'products' => [
                [
                    'product_id' => 1,
                    'name' => 'Test Widget',
                    'model' => 'TW-1',
                    'quantity' => 2,
                    'price' => 100,
                    'cost' => 40,
                ],
            ],
        ], $overrides);
    }

    public function test_creating_a_usd_order_persists_rate_and_dual_amounts(): void
    {
        $this->actingAsOrderManager();
        $usdRate = (float) DB::table('currencies')->where('code', 'USD')->value('exchange_rate');
        $usdId = (int) DB::table('currencies')->where('code', 'USD')->value('id');

        $payload = $this->baseOrderPayload(['currency_code' => 'USD']);

        $response = $this->post(route('orders.store'), $payload);
        $response->assertRedirect(route('orders.index'));

        $order = Order::orderByDesc('order_id')->first();

        $this->assertSame($usdId, (int) $order->currency_id);
        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta($usdRate, (float) $order->currency_value, 0.000001);

        $expectedPhpTotal = round(200 * $usdRate, 4);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $order->foreign_total, 0.0001);

        $line = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $expectedPhpPrice = round(100 * $usdRate, 4);
        $this->assertEqualsWithDelta($expectedPhpPrice, (float) $line->price, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $line->foreign_price, 0.0001);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $line->total, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $line->foreign_total, 0.0001);
    }

    public function test_cost_is_converted_to_default_currency_for_foreign_orders(): void
    {
        $this->actingAsOrderManager();

        $rate = 61.61429452;
        $payload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => $rate,
        ]);
        $payload['products'][0]['quantity'] = 1;
        $payload['products'][0]['price'] = 100;
        $payload['products'][0]['cost'] = 56.80;

        $response = $this->post(route('orders.store'), $payload);
        $response->assertRedirect(route('orders.index'));

        $order = Order::orderByDesc('order_id')->first();
        $line = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();

        $this->assertEqualsWithDelta(3499.6919, (float) $line->cost, 0.0001);
        $this->assertNotEqualsWithDelta(56.80, (float) $line->cost, 0.001);
    }

    public function test_creating_a_php_order_leaves_foreign_columns_null(): void
    {
        $this->actingAsOrderManager();

        $payload = $this->baseOrderPayload(['currency_code' => 'PHP']);
        $payload['products'][0]['price'] = 500;

        $response = $this->post(route('orders.store'), $payload);
        $response->assertRedirect(route('orders.index'));

        $order = Order::orderByDesc('order_id')->first();

        $this->assertSame(1.0, (float) $order->currency_value);
        $this->assertSame('PHP', $order->currency_code);
        $this->assertNull($order->foreign_total);
        $this->assertEqualsWithDelta(1000.0, (float) $order->total, 0.0001);

        $line = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $this->assertNull($line->foreign_price);
        $this->assertNull($line->foreign_total);
        $this->assertEqualsWithDelta(1000.0, (float) $line->total, 0.0001);
    }

    public function test_operator_supplied_rate_override_is_honoured_for_foreign_currency(): void
    {
        $this->actingAsOrderManager();

        $overrideRate = 61.61429452;
        $payload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => $overrideRate,
        ]);
        $payload['products'][0]['price'] = 100;
        $payload['products'][0]['quantity'] = 1;

        $response = $this->post(route('orders.store'), $payload);
        $response->assertRedirect(route('orders.index'));

        $order = Order::orderByDesc('order_id')->first();

        $this->assertEqualsWithDelta($overrideRate, (float) $order->currency_value, 0.00000001);
        $this->assertNotEqualsWithDelta(56.5, (float) $order->currency_value, 0.001);

        $expectedPhpTotal = round(100 * $overrideRate, 4);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $order->foreign_total, 0.0001);
    }

    public function test_rate_override_is_silently_discarded_for_the_default_currency(): void
    {
        $this->actingAsOrderManager();

        $payload = $this->baseOrderPayload([
            'currency_code' => 'PHP',
            'currency_rate' => 50.0,
        ]);

        $response = $this->post(route('orders.store'), $payload);
        $response->assertRedirect(route('orders.index'));

        $order = Order::orderByDesc('order_id')->first();
        $this->assertSame(1.0, (float) $order->currency_value);
        $this->assertNull($order->foreign_total);
    }

    public function test_updating_an_order_to_usd_recomputes_dual_amounts(): void
    {
        $this->actingAsOrderManager();

        $create = $this->post(route('orders.store'), $this->baseOrderPayload(['currency_code' => 'PHP']));
        $create->assertRedirect(route('orders.index'));
        $order = Order::orderByDesc('order_id')->first();

        $usdRate = (float) DB::table('currencies')->where('code', 'USD')->value('exchange_rate');
        $usdId = (int) DB::table('currencies')->where('code', 'USD')->value('id');

        $updatePayload = $this->baseOrderPayload(['currency_code' => 'USD']);
        $updatePayload['order_status_id'] = 1;
        $response = $this->put(route('orders.update', $order->order_id), $updatePayload);
        $response->assertRedirect(route('orders.edit', $order->order_id));

        $order->refresh();
        $this->assertSame($usdId, (int) $order->currency_id);
        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta($usdRate, (float) $order->currency_value, 0.000001);

        $expectedPhpTotal = round(200 * $usdRate, 4);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $order->foreign_total, 0.0001);

        $line = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $this->assertEqualsWithDelta(100.0, (float) $line->foreign_price, 0.0001);
    }

    public function test_total_is_converted_even_when_no_products_are_submitted(): void
    {
        $this->actingAsOrderManager();
        $usdRate = (float) DB::table('currencies')->where('code', 'USD')->value('exchange_rate');

        $payload = [
            'firstname'       => 'Juan',
            'lastname'        => 'Dela Cruz',
            'order_status_id' => 1,
            'currency_code'   => 'USD',
            'total'           => 100,
        ];

        $response = $this->post(route('orders.store'), $payload);
        $response->assertRedirect(route('orders.index'));

        $order = Order::orderByDesc('order_id')->first();

        $expectedPhpTotal = round(100 * $usdRate, 4);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $order->foreign_total, 0.0001);
        $this->assertNotEqualsWithDelta(100.0, (float) $order->total, 0.01);
    }

    public function test_update_converts_total_even_when_no_products_are_submitted(): void
    {
        $this->actingAsOrderManager();

        $create = $this->post(route('orders.store'), $this->baseOrderPayload(['currency_code' => 'PHP']));
        $create->assertRedirect(route('orders.index'));
        $order = Order::orderByDesc('order_id')->first();

        $usdRate = (float) DB::table('currencies')->where('code', 'USD')->value('exchange_rate');

        $updatePayload = [
            'firstname'       => 'Juan',
            'lastname'        => 'Dela Cruz',
            'order_status_id' => 1,
            'currency_code'   => 'USD',
            'total'           => 100,
        ];

        $response = $this->put(route('orders.update', $order->order_id), $updatePayload);
        $response->assertRedirect(route('orders.edit', $order->order_id));

        $order->refresh();
        $expectedPhpTotal = round(100 * $usdRate, 4);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $order->foreign_total, 0.0001);
    }

    public function test_unknown_currency_code_is_rejected_with_a_validation_error_on_store(): void
    {
        $this->actingAsOrderManager();

        $payload = $this->baseOrderPayload(['currency_code' => 'ZZZ']);
        $response = $this->postJson(route('orders.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('currency_code');
    }

    public function test_unknown_currency_code_is_rejected_with_a_validation_error_on_update(): void
    {
        $this->actingAsOrderManager();

        $create = $this->post(route('orders.store'), $this->baseOrderPayload(['currency_code' => 'PHP']));
        $create->assertRedirect(route('orders.index'));
        $order = Order::orderByDesc('order_id')->first();

        $payload = $this->baseOrderPayload(['currency_code' => 'ZZZ']);
        $response = $this->putJson(route('orders.update', $order->order_id), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('currency_code');
    }

    public function test_update_without_products_key_leaves_existing_line_items_and_options_intact(): void
    {
        $this->actingAsOrderManager();

        $create = $this->post(route('orders.store'), $this->baseOrderPayload(['currency_code' => 'PHP']));
        $create->assertRedirect(route('orders.index'));
        $order = Order::orderByDesc('order_id')->first();

        $lineBefore = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $this->assertNotNull($lineBefore, 'sanity: the order must start with a line item');

        $optionId = DB::table($this->p.'order_option')->insertGetId([
            'order_id'                => $order->order_id,
            'order_product_id'        => $lineBefore->order_product_id,
            'product_option_id'       => 0,
            'product_option_value_id' => 999,
            'name'                    => 'Color',
            'value'                   => 'Red',
            'type'                    => 'select',
        ]);

        $updatePayload = [
            'firstname'       => 'Juan',
            'lastname'        => 'Dela Cruz',
            'order_status_id' => 2,
            'currency_code'   => 'PHP',
            'comment'         => 'status bump only, line items untouched',
        ];

        $response = $this->put(route('orders.update', $order->order_id), $updatePayload);
        $response->assertRedirect(route('orders.edit', $order->order_id));

        $lineAfter = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $this->assertNotNull($lineAfter, 'line item must survive an update that omits products');
        $this->assertSame((int) $lineBefore->order_product_id, (int) $lineAfter->order_product_id);
        $this->assertEqualsWithDelta((float) $lineBefore->price, (float) $lineAfter->price, 0.0001);
        $this->assertEqualsWithDelta((float) $lineBefore->total, (float) $lineAfter->total, 0.0001);
        $this->assertEqualsWithDelta((float) $lineBefore->cost, (float) $lineAfter->cost, 0.0001);
        $this->assertSame((int) $lineBefore->quantity, (int) $lineAfter->quantity);

        $optionAfter = DB::table($this->p.'order_option')->where('order_option_id', $optionId)->first();
        $this->assertNotNull($optionAfter, 'order_option row must survive an update that omits products');
        $this->assertSame('Red', $optionAfter->value);

        $order->refresh();
        $this->assertSame(2, (int) $order->order_status_id, 'the status change itself must still take effect');
    }

    public function test_resaving_a_foreign_order_unchanged_does_not_double_convert_line_items(): void
    {
        $this->actingAsOrderManager();

        $rate = 61.61429452;
        $payload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => $rate,
        ]);
        $payload['products'][0]['quantity'] = 1;
        $payload['products'][0]['price'] = 100;
        $payload['products'][0]['cost'] = 56.80;

        $create = $this->post(route('orders.store'), $payload);
        $create->assertRedirect(route('orders.index'));
        $order = Order::orderByDesc('order_id')->first();

        $before = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $totalBefore = (float) $order->total;

        $editPage = $this->get(route('orders.edit', $order->order_id));
        $editPage->assertOk();
        $editPage->assertSee('&quot;basePrice&quot;:6161.4295', false);
        $editPage->assertSee('&quot;baseCost&quot;:3499.6919', false);
        $editPage->assertDontSee('priceIsDisplay', false);

        $displayPrice = round((float) $before->price / $rate, 2);
        $displayCost = round((float) $before->cost / $rate, 2);

        $resavePayload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => $rate,
        ]);
        $resavePayload['order_status_id'] = 1;
        $resavePayload['products'][0]['quantity'] = 1;
        $resavePayload['products'][0]['price'] = $displayPrice;
        $resavePayload['products'][0]['cost'] = $displayCost;

        $response = $this->put(route('orders.update', $order->order_id), $resavePayload);
        $response->assertRedirect(route('orders.edit', $order->order_id));

        $order->refresh();
        $after = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();

        $this->assertEqualsWithDelta((float) $before->price, (float) $after->price, 0.0001,
            'price must be byte-identical after an unchanged resave, not multiplied by the rate again');
        $this->assertEqualsWithDelta((float) $before->total, (float) $after->total, 0.0001);
        $this->assertEqualsWithDelta((float) $before->cost, (float) $after->cost, 0.0001);
        $this->assertEqualsWithDelta($totalBefore, (float) $order->total, 0.0001);

        $this->assertLessThan($totalBefore * 2, (float) $after->total);
    }

    public function test_edit_page_basePrice_is_identical_regardless_of_the_orders_currency(): void
    {
        $this->actingAsOrderManager();

        $rate = 61.61429452;

        $phpPayload = $this->baseOrderPayload(['currency_code' => 'PHP']);
        $phpPayload['products'][0]['quantity'] = 1;
        $phpPayload['products'][0]['price'] = 6161.4295;
        $phpPayload['products'][0]['cost'] = 3499.6919;
        $this->post(route('orders.store'), $phpPayload)->assertRedirect(route('orders.index'));
        $phpOrder = Order::orderByDesc('order_id')->first();

        $usdPayload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => $rate,
        ]);
        $usdPayload['products'][0]['quantity'] = 1;
        $usdPayload['products'][0]['price'] = 100;
        $usdPayload['products'][0]['cost'] = 56.80;
        $this->post(route('orders.store'), $usdPayload)->assertRedirect(route('orders.index'));
        $usdOrder = Order::orderByDesc('order_id')->first();

        $phpLine = DB::table($this->p.'order_product')->where('order_id', $phpOrder->order_id)->first();
        $usdLine = DB::table($this->p.'order_product')->where('order_id', $usdOrder->order_id)->first();
        $this->assertEqualsWithDelta((float) $phpLine->price, (float) $usdLine->price, 0.0001,
            'sanity: both orders must store the identical PHP price');

        $phpEdit = $this->get(route('orders.edit', $phpOrder->order_id));
        $usdEdit = $this->get(route('orders.edit', $usdOrder->order_id));
        $phpEdit->assertOk();
        $usdEdit->assertOk();

        $phpEdit->assertSee('&quot;basePrice&quot;:6161.4295', false);
        $usdEdit->assertSee('&quot;basePrice&quot;:6161.4295', false);
        $phpEdit->assertSee('&quot;baseCost&quot;:3499.6919', false);
        $usdEdit->assertSee('&quot;baseCost&quot;:3499.6919', false);
    }

    public function test_switching_currency_on_edit_recomputes_correctly_not_by_the_pinned_display_value(): void
    {
        $this->actingAsOrderManager();

        $originalRate = 61.61429452;
        $payload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => $originalRate,
        ]);
        $payload['products'][0]['quantity'] = 1;
        $payload['products'][0]['price'] = 100;
        $payload['products'][0]['cost'] = 56.80;

        $this->post(route('orders.store'), $payload)->assertRedirect(route('orders.index'));
        $order = Order::orderByDesc('order_id')->first();
        $before = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $rawPhpPrice = (float) $before->price;

        $newRate = 56.50000000;
        $switchedPrice = round($rawPhpPrice / $newRate, 2);
        $switchedCost = round((float) $before->cost / $newRate, 2);

        $resavePayload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => $newRate,
        ]);
        $resavePayload['order_status_id'] = 1;
        $resavePayload['products'][0]['quantity'] = 1;
        $resavePayload['products'][0]['price'] = $switchedPrice;
        $resavePayload['products'][0]['cost'] = $switchedCost;

        $this->put(route('orders.update', $order->order_id), $resavePayload)
            ->assertRedirect(route('orders.edit', $order->order_id));

        $order->refresh();
        $after = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();

        $this->assertEqualsWithDelta($rawPhpPrice, (float) $after->price, 0.3,
            'switching the rate before an otherwise-unchanged resave must preserve the PHP value');
        $this->assertEqualsWithDelta($newRate, (float) $order->currency_value, 0.00000001);

        $this->assertNotEqualsWithDelta(100 * $newRate, (float) $after->price, 1.0);
    }

    public function test_usd_order_with_rate_one_still_stores_foreign_amounts_and_does_not_masquerade_as_php(): void
    {
        $this->actingAsOrderManager();

        $payload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => 1,
        ]);
        $payload['products'][0]['quantity'] = 1;
        $payload['products'][0]['price'] = 100;

        $response = $this->post(route('orders.store'), $payload);
        $response->assertRedirect(route('orders.index'));

        $order = Order::orderByDesc('order_id')->first();

        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta(1.0, (float) $order->currency_value, 0.00000001,
            'sanity: the operator-entered rate of 1 is honoured, this is not about overriding it');
        $this->assertNotNull($order->foreign_total,
            'a USD order must never masquerade as PHP just because its rate happens to be 1');
        $this->assertEqualsWithDelta(100.0, (float) $order->foreign_total, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $order->total, 0.0001,
            'toBase(100, rate=1) is a no-op - total is numerically 100, but it is now PHP100, not USD100');

        $line = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $this->assertNotNull($line->foreign_price);
        $this->assertEqualsWithDelta(100.0, (float) $line->foreign_price, 0.0001);
    }

    public function test_create_page_does_not_double_divide_old_input_after_validation_failure(): void
    {
        $this->actingAsOrderManager();

        $rate = 61.61429452;
        $payload = [
            'lastname'        => 'Dela Cruz',
            'order_status_id' => 1,
            'currency_code'   => 'USD',
            'currency_rate'   => $rate,
            'products'        => [
                [
                    'product_id' => 1,
                    'name'       => 'Test Widget',
                    'model'      => 'TW-1',
                    'quantity'   => 1,
                    'price'      => 100,
                    'cost'       => 56.80,
                ],
            ],
        ];

        $response = $this->post(route('orders.store'), $payload);
        $response->assertSessionHasErrors('firstname');

        $createPage = $this->get(route('orders.create'));
        $createPage->assertOk();

        $expectedBasePrice = 100 * $rate;
        $createPage->assertSee('&quot;basePrice&quot;:' . $expectedBasePrice, false);
        $createPage->assertDontSee('&quot;basePrice&quot;:100', false);
    }
}
