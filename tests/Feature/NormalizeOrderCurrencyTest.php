<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormalizeOrderCurrencyTest extends TestCase
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

    private function makeLine(int $orderId, int $lineId, float $price, float $total): void
    {
        DB::table($this->p.'order_product')->insert([
            'order_product_id' => $lineId, 'order_id' => $orderId, 'product_id' => 1,
            'name' => 'Test Product', 'model' => 'TP-1', 'quantity' => 2,
            'price' => $price, 'total' => $total, 'cost' => 0, 'tax' => 0, 'reward' => 0,
        ]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->makeOrder(8827, 'USD', 0.02100927, 6602.08);
        $this->makeLine(8827, 1, 2790.00, 5580.00);

        $this->artisan('orders:currency-normalize --dry-run')->assertExitCode(0);

        $order = DB::table($this->p.'order')->where('order_id', 8827)->first();
        $this->assertNull($order->foreign_total);
        $this->assertEquals(0.02100927, (float) $order->currency_value);
    }

    public function test_legacy_order_is_normalized_without_moving_its_total(): void
    {
        $this->makeOrder(8827, 'USD', 0.02100927, 6602.08);
        $this->makeLine(8827, 1, 2790.00, 5580.00);

        $this->artisan('orders:currency-normalize --execute')->assertExitCode(0);

        $order = DB::table($this->p.'order')->where('order_id', 8827)->first();
        $this->assertEquals(6602.08, (float) $order->total, 'authoritative total must not move');
        $this->assertEquals(138.7049, (float) $order->foreign_total);
        $this->assertEquals(47.59803649, (float) $order->currency_value);

        $line = DB::table($this->p.'order_product')->where('order_product_id', 1)->first();
        $this->assertEquals(2790.00, (float) $line->price, 'line price must not move');
        $this->assertEquals(58.6159, (float) $line->foreign_price);
        $this->assertEquals(117.2317, (float) $line->foreign_total);
    }

    public function test_default_currency_order_is_untouched(): void
    {
        $this->makeOrder(100, 'PHP', 1.0, 1000.00);
        $this->makeLine(100, 2, 500.00, 1000.00);

        $this->artisan('orders:currency-normalize --execute')->assertExitCode(0);

        $order = DB::table($this->p.'order')->where('order_id', 100)->first();
        $this->assertEquals(1000.00, (float) $order->total);
        $this->assertNull($order->foreign_total);
    }

    public function test_lost_rate_order_is_reported_but_not_touched(): void
    {
        $this->makeOrder(44294, 'USD', 1.0, 5000.00);
        $this->makeLine(44294, 3, 78.00, 3900.00);

        $this->artisan('orders:currency-normalize --execute')->assertExitCode(0);

        $order = DB::table($this->p.'order')->where('order_id', 44294)->first();
        $this->assertEquals(5000.00, (float) $order->total, 'lost-rate order must not be guessed at');
        $this->assertNull($order->foreign_total);
        $this->assertEquals(1.0, (float) $order->currency_value);
    }

    public function test_a_corrupt_row_is_skipped_and_reported_without_aborting_the_whole_run(): void
    {
        $this->makeOrder(70001, 'USD', -5.0, 1000.00);
        $this->makeLine(70001, 101, 500.00, 1000.00);

        $this->makeOrder(8827, 'USD', 0.02100927, 6602.08);
        $this->makeLine(8827, 1, 2790.00, 5580.00);

        $this->artisan('orders:currency-normalize --execute')
            ->assertExitCode(0);

        $bad = DB::table($this->p.'order')->where('order_id', 70001)->first();
        $this->assertNull($bad->foreign_total);
        $this->assertEquals(-5.0, (float) $bad->currency_value);
        $this->assertEquals(1000.00, (float) $bad->total);

        $good = DB::table($this->p.'order')->where('order_id', 8827)->first();
        $this->assertEquals(6602.08, (float) $good->total, 'authoritative total must not move');
        $this->assertEquals(138.7049, (float) $good->foreign_total);
        $this->assertEquals(47.59803649, (float) $good->currency_value);
    }

    public function test_running_twice_is_idempotent(): void
    {
        $this->makeOrder(8827, 'USD', 0.02100927, 6602.08);
        $this->makeLine(8827, 1, 2790.00, 5580.00);

        $this->artisan('orders:currency-normalize --execute')->assertExitCode(0);
        $this->artisan('orders:currency-normalize --execute')->assertExitCode(0);

        $order = DB::table($this->p.'order')->where('order_id', 8827)->first();
        $this->assertEquals(6602.08, (float) $order->total);
        $this->assertEquals(47.59803649, (float) $order->currency_value, 'must not double-invert');
    }
}
