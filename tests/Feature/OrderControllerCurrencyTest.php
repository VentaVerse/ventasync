<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 6: OrderController is the write path that originally hardcoded
 * currency_value=1 / currency_id=0 regardless of what the operator entered,
 * which is the root cause this whole project repairs. These tests exercise
 * the real HTTP routes (not the model directly) so a regression here is
 * caught the same way the original bug would have been.
 */
class OrderControllerCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private string $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = (string) config('catalog.prefix');
    }

    /**
     * manage_orders is seeded by migrations (2026_02_19_100000_add_granular_permissions);
     * build a user group that carries it and act as a user in that group so the
     * `perm:manage_orders` middleware on the orders routes lets requests through.
     */
    private function actingAsOrderManager(): User
    {
        $group = UserGroup::create(['name' => 'Order Managers']);
        $permission = Permission::where('key', 'manage_orders')->firstOrFail();
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

        // Authoritative total is in PHP (default currency); foreign_total holds
        // the as-transacted USD amount.
        $expectedPhpTotal = round(200 * $usdRate, 4); // qty 2 * $100
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $order->foreign_total, 0.0001);

        $line = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $expectedPhpPrice = round(100 * $usdRate, 4);
        $this->assertEqualsWithDelta($expectedPhpPrice, (float) $line->price, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $line->foreign_price, 0.0001);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $line->total, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $line->foreign_total, 0.0001);
    }

    /**
     * Regression: cost must go through the same toBase() conversion as
     * price/total. The frontend already converts cost client-side (see
     * orders/create.blade.php:380 and edit.blade.php:424,440) before
     * submitting, so a foreign order's `cost` arrives in order currency -
     * writing it straight through leaves price/total in PHP but cost in the
     * foreign currency on the same row, corrupting COGS/profitability
     * reporting. Order #44294's original cost of 56.8000 (= PHP3,500 /
     * 61.61429452) is exactly what an un-converted write looks like.
     *
     * Numbers are chosen so an accidental pass-through cannot coincidentally
     * match: 56.80 raw vs 3499.6919 converted are unmistakably different.
     */
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
        // 2 x 100 PHP
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

        // The override - not the currency's stored feed rate (56.5) - must be
        // what gets frozen onto the order.
        $this->assertEqualsWithDelta($overrideRate, (float) $order->currency_value, 0.00000001);
        $this->assertNotEqualsWithDelta(56.5, (float) $order->currency_value, 0.001);

        $expectedPhpTotal = round(100 * $overrideRate, 4);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $order->foreign_total, 0.0001);
    }

    public function test_rate_override_is_silently_discarded_for_the_default_currency(): void
    {
        // Documented quirk of OrderCurrencyService::resolve(): the default
        // currency is always forced to rate=1.0, even if the caller passes an
        // override. Guard against a regression that would let a PHP order be
        // frozen at some other rate.
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

        // Create as PHP first.
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
        // update() previously never wrote currency_id at all - pin it down.
        $this->assertSame($usdId, (int) $order->currency_id);
        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta($usdRate, (float) $order->currency_value, 0.000001);

        $expectedPhpTotal = round(200 * $usdRate, 4);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $order->foreign_total, 0.0001);

        $line = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $this->assertEqualsWithDelta(100.0, (float) $line->foreign_price, 0.0001);
    }

    /**
     * Regression: an order-level total submitted without any line items must
     * still be converted from order currency to PHP, and foreign_total must
     * still be populated. Before this fix, the raw client figure (in USD)
     * was written straight into the PHP `total` column whenever `products`
     * was empty - the same class of bug as the cost issue above, just on a
     * different field.
     */
    public function test_total_is_converted_even_when_no_products_are_submitted(): void
    {
        $this->actingAsOrderManager();
        $usdRate = (float) DB::table('currencies')->where('code', 'USD')->value('exchange_rate');

        $payload = [
            'firstname'       => 'Juan',
            'lastname'        => 'Dela Cruz',
            'order_status_id' => 1,
            'currency_code'   => 'USD',
            'total'           => 100, // order-currency (USD) amount; no line items submitted
        ];

        $response = $this->post(route('orders.store'), $payload);
        $response->assertRedirect(route('orders.index'));

        $order = Order::orderByDesc('order_id')->first();

        $expectedPhpTotal = round(100 * $usdRate, 4);
        $this->assertEqualsWithDelta($expectedPhpTotal, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $order->foreign_total, 0.0001);
        $this->assertNotEqualsWithDelta(100.0, (float) $order->total, 0.01);
    }

    /**
     * Same invariant on the update() path: changing an order's currency
     * without resubmitting products must not leave the order-level total
     * stale or unconverted.
     */
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

    /**
     * Important-severity fix: an unrecognized currency_code must fail
     * validation (422), not blow up resolve()'s InvalidArgumentException
     * into an uncaught 500. Confirmed pre-fix by posting currency_code=ZZZ.
     */
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

    /**
     * Fix round 2, Critical: update() used to delete every order_product
     * (and, via cascading order_option deletes) unconditionally, before
     * checking whether `products` had even been submitted. An update that
     * only touches status/customer fields - the exact shape round-1's own
     * `test_update_converts_total_even_when_no_products_are_submitted`
     * exercises - silently emptied the order's line items. The fix guards
     * the whole delete-and-rebuild block on `$request->has('products')`
     * rather than the array being non-empty, so an absent key leaves
     * existing order_product/order_option rows completely untouched.
     */
    public function test_update_without_products_key_leaves_existing_line_items_and_options_intact(): void
    {
        $this->actingAsOrderManager();

        $create = $this->post(route('orders.store'), $this->baseOrderPayload(['currency_code' => 'PHP']));
        $create->assertRedirect(route('orders.index'));
        $order = Order::orderByDesc('order_id')->first();

        $lineBefore = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $this->assertNotNull($lineBefore, 'sanity: the order must start with a line item');

        // Attach an order_option row to that line, as a real variation would
        // have, to prove options survive the update too.
        $optionId = DB::table($this->p.'order_option')->insertGetId([
            'order_id'                => $order->order_id,
            'order_product_id'        => $lineBefore->order_product_id,
            'product_option_id'       => 0,
            'product_option_value_id' => 999,
            'name'                    => 'Color',
            'value'                   => 'Red',
            'type'                    => 'select',
        ]);

        // Update WITHOUT a `products` key at all - only status/comment change,
        // exactly the shape the edit form's own currency-only or
        // status-only saves can produce.
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

    /**
     * Fix round 2, Important (superseded by the final whole-branch review's
     * C3 fix): edit.blade.php used to pre-populate its line items by
     * treating `order_product.price`/`cost` as already-in-order-currency
     * ("priceIsDisplay: true", fed from a `display_price`/`display_cost`
     * pre-conversion in OrderController::edit()). That pin froze the
     * displayed figure to the rate at PAGE LOAD time; switching currency
     * afterwards changed the rate field without touching the pinned figure,
     * so a resave after a currency switch silently multiplied/divided the
     * order by the new rate (see
     * test_switching_currency_on_edit_recomputes_correctly_not_by_the_pinned_display_value
     * below).
     *
     * The fix: drop display_price/display_cost and the pin entirely.
     * basePrice/baseCost in the edit page's JS are now always the raw
     * stored PHP figure (rate-independent, exactly like a freshly-added
     * line item), and convertPrice() (divide by whatever rate is currently
     * in the field) derives the order-currency figure on every render. This
     * test keeps proving the original property - an unchanged resave must
     * not double-convert - updated to assert against the new (correct)
     * literal the page renders: the raw PHP figure, not a
     * pre-converted one.
     */
    public function test_resaving_a_foreign_order_unchanged_does_not_double_convert_line_items(): void
    {
        $this->actingAsOrderManager();

        $rate = 61.61429452;
        $payload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => $rate,
        ]);
        $payload['products'][0]['quantity'] = 1;
        $payload['products'][0]['price'] = 100;   // USD
        $payload['products'][0]['cost'] = 56.80;  // USD

        $create = $this->post(route('orders.store'), $payload);
        $create->assertRedirect(route('orders.index'));
        $order = Order::orderByDesc('order_id')->first();

        $before = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $totalBefore = (float) $order->total;

        // Direct proof of the view-layer fix: the edit page must render the
        // raw stored PHP figure (6161.4295 / 3499.6919) into the JS,
        // rate-independent - not a value pre-converted to the order's
        // currency at load time (100 / 56.8), which is what let a later
        // currency switch corrupt the figure without basePrice itself ever
        // changing.
        $editPage = $this->get(route('orders.edit', $order->order_id));
        $editPage->assertOk();
        $editPage->assertSee('basePrice: 6161.4295', false);
        $editPage->assertSee('baseCost: 3499.6919', false);
        $editPage->assertDontSee('priceIsDisplay', false);

        // What the (fixed) edit page would submit for this exact line,
        // unchanged: convertPrice(basePrice) = PHP / rate, rounded to 2dp by
        // the <input step="0.01"> before being written into the hidden field.
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

        // A double-conversion bug would multiply these by ~61.6 - assert we
        // are nowhere near that, not just "different by a rounding amount".
        $this->assertLessThan($totalBefore * 2, (float) $after->total);
    }

    /**
     * C3 (critical, final whole-branch review): switching the currency
     * dropdown on the edit page used to leave the pinned display figure
     * numerically unchanged (only re-labelled), so the submitted price was
     * the OLD order-currency figure interpreted under the NEW rate. A PHP
     * order at 3,500 switched to USD stored 197,750; a USD order switched
     * to PHP collapsed 3,500 to 56.80.
     *
     * The fix makes basePrice a rate-independent, raw-PHP figure, so this is
     * provable structurally: two orders that share the same underlying PHP
     * price but differ in currency/rate must render the IDENTICAL basePrice
     * literal on their edit pages. That is precisely what guarantees
     * convertPrice() recomputes the right order-currency figure from
     * whatever rate the operator has just switched to, without basePrice
     * itself needing to change.
     */
    public function test_edit_page_basePrice_is_identical_regardless_of_the_orders_currency(): void
    {
        $this->actingAsOrderManager();

        $rate = 61.61429452;

        // Order A: PHP order, raw stored price is directly the submitted
        // figure (rate=1 is a no-op conversion).
        $phpPayload = $this->baseOrderPayload(['currency_code' => 'PHP']);
        $phpPayload['products'][0]['quantity'] = 1;
        $phpPayload['products'][0]['price'] = 6161.4295;
        $phpPayload['products'][0]['cost'] = 3499.6919;
        $this->post(route('orders.store'), $phpPayload)->assertRedirect(route('orders.index'));
        $phpOrder = Order::orderByDesc('order_id')->first();

        // Order B: USD order engineered to store the SAME raw PHP price -
        // 100 USD * 61.61429452 = 6161.4295, matching order A exactly.
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

        // Both pages must render the SAME basePrice/baseCost literal - proof
        // that pre-population no longer depends on the order's currency or
        // rate, which is what makes a later currency switch on either page
        // safe: only the rate field changes, and convertPrice() recomputes
        // the display/submit value from the same unchanged basePrice.
        $phpEdit->assertSee('basePrice: 6161.4295', false);
        $usdEdit->assertSee('basePrice: 6161.4295', false);
        $phpEdit->assertSee('baseCost: 3499.6919', false);
        $usdEdit->assertSee('baseCost: 3499.6919', false);
    }

    /**
     * End-to-end proof that a currency switch on the edit page now round-
     * trips correctly. Simulates exactly what the fixed JS computes: the
     * operator switches currency (or edits the rate field) BEFORE saving, so
     * the submitted price is convertPrice(basePrice) under the NEW rate, not
     * the stale figure under the OLD rate. If update() still worked from a
     * pinned display value (the pre-fix behaviour), this would corrupt the
     * PHP total by the ratio of the two rates instead of preserving it.
     */
    public function test_switching_currency_on_edit_recomputes_correctly_not_by_the_pinned_display_value(): void
    {
        $this->actingAsOrderManager();

        $originalRate = 61.61429452;
        $payload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => $originalRate,
        ]);
        $payload['products'][0]['quantity'] = 1;
        $payload['products'][0]['price'] = 100;   // USD
        $payload['products'][0]['cost'] = 56.80;  // USD

        $this->post(route('orders.store'), $payload)->assertRedirect(route('orders.index'));
        $order = Order::orderByDesc('order_id')->first();
        $before = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $rawPhpPrice = (float) $before->price; // 6161.4295

        // Operator switches the rate field to a different value before
        // saving (e.g. editing the rate, or picking a different currency
        // with a different feed rate) WITHOUT touching the line item. The
        // fixed JS recomputes: convertPrice(basePrice) = rawPhpPrice / newRate.
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

        // The PHP price must round-trip back to (approximately) what it was
        // before the switch, not be corrupted by the ratio of the two rates.
        // Delta accounts for the <input step="0.01"> truncation on the
        // order-currency figure at the new rate (max ~newRate * 0.005) - a
        // rounding-sized gap, unmistakably different from the old bug's
        // ~500-unit corruption asserted below.
        $this->assertEqualsWithDelta($rawPhpPrice, (float) $after->price, 0.3,
            'switching the rate before an otherwise-unchanged resave must preserve the PHP value');
        $this->assertEqualsWithDelta($newRate, (float) $order->currency_value, 0.00000001);

        // The old pinned-value bug would have submitted the ORIGINAL
        // order-currency figure (100) re-interpreted under the new rate
        // (100 * 56.5 = 5650), wildly different from the correct ~6161.43.
        $this->assertNotEqualsWithDelta(100 * $newRate, (float) $after->price, 1.0);
    }

    /**
     * I2 (important, final whole-branch review): `$isDefault` used to be
     * decided by `OrderCurrencyService::isDefaultRate($rate)` - i.e. whether
     * the resolved rate happened to equal 1.0 - rather than by currency
     * identity. `currency_rate` only validates `gt:0`, so an operator (or a
     * fat-fingered form submission) entering "1" for USD reproduced the
     * original bug exactly: USD numerals land in the PHP columns,
     * foreign_total stays NULL, and currency_value is 1 - a USD order
     * masquerading as a PHP one. The fix discriminates on the resolved
     * currency's identity (`resolve()`'s `is_default` flag), not on what the
     * rate happens to equal.
     */
    public function test_usd_order_with_rate_one_still_stores_foreign_amounts_and_does_not_masquerade_as_php(): void
    {
        $this->actingAsOrderManager();

        $payload = $this->baseOrderPayload([
            'currency_code' => 'USD',
            'currency_rate' => 1, // operator error / fat-fingered rate
        ]);
        $payload['products'][0]['quantity'] = 1;
        $payload['products'][0]['price'] = 100;

        $response = $this->post(route('orders.store'), $payload);
        $response->assertRedirect(route('orders.index'));

        $order = Order::orderByDesc('order_id')->first();

        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta(1.0, (float) $order->currency_value, 0.00000001,
            'sanity: the operator-entered rate of 1 is honoured, this is not about overriding it');
        // The bug: foreign_total staying NULL and total holding the raw USD
        // figure (100) unconverted - a USD order that is indistinguishable
        // from a PHP one.
        $this->assertNotNull($order->foreign_total,
            'a USD order must never masquerade as PHP just because its rate happens to be 1');
        $this->assertEqualsWithDelta(100.0, (float) $order->foreign_total, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $order->total, 0.0001,
            'toBase(100, rate=1) is a no-op - total is numerically 100, but it is now PHP100, not USD100');

        $line = DB::table($this->p.'order_product')->where('order_id', $order->order_id)->first();
        $this->assertNotNull($line->foreign_price);
        $this->assertEqualsWithDelta(100.0, (float) $line->foreign_price, 0.0001);
    }

    /**
     * I1 (important, final whole-branch review): create.blade.php has no
     * priceIsDisplay support and its renderProducts() calls convertPrice()
     * unconditionally, but old('products')[i][price] is an order-currency
     * figure (what the form actually submitted), not PHP. Without
     * multiplying back by the rate that was in effect at submit time, a
     * USD line at $100 re-rendered as $100 / rate (~$1.77) after a
     * validation failure, and if saved unchanged would have stored PHP100
     * instead of the original ~PHP6,161.
     */
    public function test_create_page_does_not_double_divide_old_input_after_validation_failure(): void
    {
        $this->actingAsOrderManager();

        $rate = 61.61429452;
        $payload = [
            // firstname omitted on purpose - forces the validation failure
            // this test needs to exercise the old()-input re-render path.
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
                    'price'      => 100,   // USD, order-currency, as the (fixed) JS submits it
                    'cost'       => 56.80, // USD
                ],
            ],
        ];

        $response = $this->post(route('orders.store'), $payload);
        $response->assertSessionHasErrors('firstname');

        $createPage = $this->get(route('orders.create'));
        $createPage->assertOk();

        // basePrice must be re-derived back to PHP (100 * rate), not left as
        // the raw order-currency figure - the latter is exactly what a
        // second, unconditional convertPrice() division would corrupt.
        $expectedBasePrice = 100 * $rate;
        $createPage->assertSee('"basePrice":' . $expectedBasePrice, false);
        $createPage->assertDontSee('"basePrice":100', false);
    }
}
