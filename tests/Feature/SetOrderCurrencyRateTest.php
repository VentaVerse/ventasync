<?php

namespace Tests\Feature;

use App\Services\OrderCurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SetOrderCurrencyRateTest extends TestCase
{
    use RefreshDatabase;

    private string $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = (string) config('catalog.prefix');
    }

    private function makeOrder(int $id, string $code, float $cv, float $total): void
    {
        DB::table($this->p.'order')->insert([
            'order_id' => $id, 'currency_code' => $code, 'currency_value' => $cv,
            'total' => $total, 'order_status_id' => 1, 'date_added' => '2021-04-19 00:00:00',
            'date_modified' => '2021-04-19 00:00:00',
            'invoice_prefix' => 'INV-', 'store_name' => '', 'store_url' => '',
            'firstname' => '', 'lastname' => '', 'email' => '', 'telephone' => '', 'fax' => '',
            'custom_field' => '',
            'payment_firstname' => '', 'payment_lastname' => '', 'payment_company' => '',
            'payment_address_1' => '', 'payment_address_2' => '', 'payment_city' => '',
            'payment_postcode' => '', 'payment_country' => '', 'payment_country_id' => 0,
            'payment_zone' => '', 'payment_zone_id' => 0,
            'payment_address_format' => '', 'payment_custom_field' => '',
            'payment_method' => '', 'payment_code' => '',
            'shipping_firstname' => '', 'shipping_lastname' => '', 'shipping_company' => '',
            'shipping_address_1' => '', 'shipping_address_2' => '', 'shipping_city' => '',
            'shipping_postcode' => '', 'shipping_country' => '', 'shipping_country_id' => 0,
            'shipping_zone' => '', 'shipping_zone_id' => 0,
            'shipping_address_format' => '', 'shipping_custom_field' => '',
            'shipping_method' => '', 'shipping_code' => '',
            'comment' => '', 'affiliate_id' => 0, 'commission' => 0,
            'marketing_id' => 0, 'tracking' => '', 'language_id' => 0, 'currency_id' => 0,
            'ip' => '', 'forwarded_ip' => '', 'user_agent' => '', 'accept_language' => '',
            'courier_id' => 0, 'tracking_number' => '', 'oe_import' => 0,
        ]);
    }

    private function makeLine(int $orderId, int $lineId, string $name, string $model, int $qty, float $price, float $total, float $cost): void
    {
        DB::table($this->p.'order_product')->insert([
            'order_product_id' => $lineId, 'order_id' => $orderId, 'product_id' => 1,
            'name' => $name, 'model' => $model, 'quantity' => $qty,
            'price' => $price, 'total' => $total, 'cost' => $cost, 'tax' => 0, 'reward' => 0,
        ]);
    }

    private function makeTotal(int $totalId, int $orderId, string $code, string $title, float $value, int $sort): void
    {
        DB::table($this->p.'order_total')->insert([
            'order_total_id' => $totalId, 'order_id' => $orderId,
            'code' => $code, 'title' => $title, 'value' => $value, 'sort_order' => $sort,
        ]);
    }

    private function seed44294BeforeState(): void
    {
        $this->makeOrder(44294, 'USD', 1.00000000, 5000.0000);
        $this->makeLine(44294, 12631574, 'Valeton GP-50 Multi Effects AMP, IR and NAM Profile Loaders Dual Switch Guitar Pedal', 'GP-50', 50, 78.0000, 3900.0000, 56.8000);
        $this->makeLine(44294, 12631575, 'Valeton GP-150 Guitar Multi-Effect Processor', 'gp150', 10, 110.0000, 1100.0000, 81.1500);
        $this->makeTotal(21046743, 44294, 'sub_total', 'Sub-Total', 5000.0000, 1);
        $this->makeTotal(21046744, 44294, 'total', 'Total', 5000.0000, 9);
    }

    public function test_preview_writes_nothing(): void
    {
        $this->seed44294BeforeState();

        $this->artisan('orders:currency-set-rate 44294 61.61429452')->assertExitCode(0);

        $order = DB::table($this->p.'order')->where('order_id', 44294)->first();
        $this->assertNull($order->foreign_total);
        $this->assertEquals(1.0, (float) $order->currency_value);
        $this->assertEquals(5000.0000, (float) $order->total);

        $gp50 = DB::table($this->p.'order_product')->where('order_product_id', 12631574)->first();
        $this->assertNull($gp50->foreign_price);
        $this->assertNull($gp50->foreign_total);
        $this->assertEquals(78.0000, (float) $gp50->price);
        $this->assertEquals(56.8000, (float) $gp50->cost);

        $gp150 = DB::table($this->p.'order_product')->where('order_product_id', 12631575)->first();
        $this->assertNull($gp150->foreign_price);
        $this->assertNull($gp150->foreign_total);
        $this->assertEquals(110.0000, (float) $gp150->price);
        $this->assertEquals(81.1500, (float) $gp150->cost);

        $subTotal = DB::table($this->p.'order_total')->where('order_total_id', 21046743)->first();
        $this->assertEquals(5000.0000, (float) $subTotal->value);

        $total = DB::table($this->p.'order_total')->where('order_total_id', 21046744)->first();
        $this->assertEquals(5000.0000, (float) $total->value);
    }

    public function test_execute_converts_order_44294_to_exactly_the_sandbox_after_state(): void
    {
        $this->seed44294BeforeState();

        $this->artisan('orders:currency-set-rate 44294 61.61429452 --execute')->assertExitCode(0);

        $order = DB::table($this->p.'order')->where('order_id', 44294)->first();
        $this->assertSame('USD', $order->currency_code);
        $this->assertEquals(61.61429452, (float) $order->currency_value);
        $this->assertEquals(308071.4726, (float) $order->total);
        $this->assertEquals(5000.0000, (float) $order->foreign_total);

        $gp50 = DB::table($this->p.'order_product')->where('order_product_id', 12631574)->first();
        $this->assertEquals(50, $gp50->quantity);
        $this->assertEquals(4805.9150, (float) $gp50->price);
        $this->assertEquals(78.0000, (float) $gp50->foreign_price);
        $this->assertEquals(240295.7486, (float) $gp50->total);
        $this->assertEquals(3900.0000, (float) $gp50->foreign_total);
        $this->assertEquals(3499.6919, (float) $gp50->cost);

        $gp150 = DB::table($this->p.'order_product')->where('order_product_id', 12631575)->first();
        $this->assertEquals(10, $gp150->quantity);
        $this->assertEquals(6777.5724, (float) $gp150->price);
        $this->assertEquals(110.0000, (float) $gp150->foreign_price);
        $this->assertEquals(67775.7240, (float) $gp150->total);
        $this->assertEquals(1100.0000, (float) $gp150->foreign_total);
        $this->assertEquals(5000.0000, (float) $gp150->cost);

        $subTotal = DB::table($this->p.'order_total')->where('order_total_id', 21046743)->first();
        $this->assertEquals(308071.4726, (float) $subTotal->value);

        $total = DB::table($this->p.'order_total')->where('order_total_id', 21046744)->first();
        $this->assertEquals(308071.4726, (float) $total->value);
    }

    public function test_order_total_multi_code_rows_scale_and_preserve_the_additive_identity(): void
    {
        $rate = 61.61429452;

        $this->makeOrder(900001, 'USD', 1.00000000, 4000.0000);
        $this->makeLine(900001, 90000101, 'Synthetic Line', 'SYN-1', 1, 4500.0000, 4500.0000, 0.0000);
        $this->makeTotal(90000001, 900001, 'sub_total', 'Sub-Total', 4500.0000, 1);
        $this->makeTotal(90000002, 900001, 'shipping', 'Shipping', 300.0000, 3);
        $this->makeTotal(90000003, 900001, 'partial_payment_total', 'Partial Payment', -800.0000, 5);
        $this->makeTotal(90000004, 900001, 'total', 'Total', 4000.0000, 9);

        $this->assertEqualsWithDelta(4000.0000, 4500.0000 + 300.0000 - 800.0000, 0.0001);

        $this->artisan("orders:currency-set-rate 900001 {$rate} --execute")->assertExitCode(0);

        $rows = DB::table($this->p.'order_total')->where('order_id', 900001)->get()->keyBy('code');

        $this->assertEqualsWithDelta(OrderCurrencyService::toBase(4500.0000, $rate), (float) $rows['sub_total']->value, 0.0001);
        $this->assertEqualsWithDelta(OrderCurrencyService::toBase(300.0000, $rate), (float) $rows['shipping']->value, 0.0001);
        $this->assertEqualsWithDelta(OrderCurrencyService::toBase(4000.0000, $rate), (float) $rows['total']->value, 0.0001);

        $partial = (float) $rows['partial_payment_total']->value;
        $this->assertLessThan(0, $partial, 'partial_payment_total must remain negative after conversion');
        $this->assertEqualsWithDelta(OrderCurrencyService::toBase(-800.0000, $rate), $partial, 0.0001);
        $this->assertEqualsWithDelta(-OrderCurrencyService::toBase(800.0000, $rate), $partial, 0.0001);

        $convertedSum = (float) $rows['sub_total']->value + (float) $rows['shipping']->value + $partial;
        $this->assertEqualsWithDelta((float) $rows['total']->value, $convertedSum, 0.0001, 'converted components must still sum to the converted total');

        $order = DB::table($this->p.'order')->where('order_id', 900001)->first();
        $this->assertEqualsWithDelta((float) $rows['total']->value, (float) $order->total, 0.0001, 'order.total must agree with the total-code order_total row');
        $this->assertEqualsWithDelta(4000.0000, (float) $order->foreign_total, 0.0001);
    }

    public function test_double_run_guard_refuses_and_changes_nothing(): void
    {
        $this->seed44294BeforeState();

        $this->artisan('orders:currency-set-rate 44294 61.61429452 --execute')->assertExitCode(0);

        $before = DB::table($this->p.'order')->where('order_id', 44294)->first();
        $beforeLines = DB::table($this->p.'order_product')->where('order_id', 44294)->orderBy('order_product_id')->get();

        $this->artisan('orders:currency-set-rate 44294 61.61429452 --execute')->assertExitCode(1);

        $after = DB::table($this->p.'order')->where('order_id', 44294)->first();
        $afterLines = DB::table($this->p.'order_product')->where('order_id', 44294)->orderBy('order_product_id')->get();

        $this->assertEquals($before, $after, 'a second --execute run must change nothing on the order row');
        $this->assertEquals($beforeLines, $afterLines, 'a second --execute run must change nothing on the order_product rows');
    }

    public function test_default_currency_order_is_rejected(): void
    {
        $this->makeOrder(100, 'PHP', 1.0, 1000.0000);
        $this->makeLine(100, 1, 'Test Product', 'TP-1', 2, 500.0000, 1000.0000, 0);

        $this->artisan('orders:currency-set-rate 100 61.61429452 --execute')->assertExitCode(1);

        $order = DB::table($this->p.'order')->where('order_id', 100)->first();
        $this->assertEquals(1000.0000, (float) $order->total);
        $this->assertNull($order->foreign_total);
    }

    public function test_nonpositive_rate_is_rejected(): void
    {
        $this->seed44294BeforeState();

        $this->artisan('orders:currency-set-rate 44294 0 --execute')->assertExitCode(1);

        $order = DB::table($this->p.'order')->where('order_id', 44294)->first();
        $this->assertNull($order->foreign_total);
        $this->assertEquals(1.0, (float) $order->currency_value);
    }

    public function test_unknown_order_is_rejected(): void
    {
        $this->artisan('orders:currency-set-rate 999999 61.61429452 --execute')->assertExitCode(1);
    }

    public function test_legacy_convention_order_is_refused_and_left_untouched(): void
    {
        $this->makeOrder(8827, 'USD', 0.02100927, 6602.0800);
        $this->makeLine(8827, 1, 'Legacy Line', 'LEG-1', 1, 6602.0800, 6602.0800, 5000.0000);
        $this->makeTotal(1, 8827, 'total', 'Total', 6602.0800, 9);

        $this->artisan('orders:currency-set-rate 8827 47.59803649 --execute')->assertExitCode(1);

        $order = DB::table($this->p.'order')->where('order_id', 8827)->first();
        $this->assertNull($order->foreign_total, 'must not be marked normalized');
        $this->assertEqualsWithDelta(0.02100927, (float) $order->currency_value, 0.00000001,
            'the legacy rate must survive untouched');
        $this->assertEqualsWithDelta(6602.0800, (float) $order->total, 0.0001,
            'the already-correct PHP total must not be inflated by the rate');

        $line = DB::table($this->p.'order_product')->where('order_product_id', 1)->first();
        $this->assertNull($line->foreign_price);
        $this->assertNull($line->foreign_total);
        $this->assertEqualsWithDelta(6602.0800, (float) $line->price, 0.0001);

        $total = DB::table($this->p.'order_total')->where('order_total_id', 1)->first();
        $this->assertEqualsWithDelta(6602.0800, (float) $total->value, 0.0001);
    }
}
