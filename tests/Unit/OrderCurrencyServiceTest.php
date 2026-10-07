<?php

namespace Tests\Unit;

use App\Services\OrderCurrencyService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OrderCurrencyServiceTest extends TestCase
{
    public function test_to_base_multiplies_foreign_by_rate(): void
    {
        $this->assertSame(4805.9150, OrderCurrencyService::toBase(78.0, 61.61429452));
    }

    public function test_to_foreign_divides_base_by_rate(): void
    {
        $this->assertSame(78.0, OrderCurrencyService::toForeign(4805.9150, 61.61429452));
    }

    public function test_round_trip_is_stable(): void
    {
        $base = OrderCurrencyService::toBase(110.0, 61.61429452);
        $this->assertSame(110.0, OrderCurrencyService::toForeign($base, 61.61429452));
    }

    public function test_rate_of_one_is_identity(): void
    {
        $this->assertSame(2790.0, OrderCurrencyService::toBase(2790.0, 1.0));
        $this->assertSame(2790.0, OrderCurrencyService::toForeign(2790.0, 1.0));
    }

    public function test_results_are_rounded_to_four_decimals(): void
    {
        $this->assertSame(33.3333, OrderCurrencyService::toBase(1.0, 33.33333333));
    }

    public function test_zero_rate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OrderCurrencyService::toBase(78.0, 0.0);
    }

    public function test_negative_rate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OrderCurrencyService::toForeign(78.0, -1.0);
    }

    public function test_is_default_rate_tolerates_float_noise(): void
    {
        $this->assertTrue(OrderCurrencyService::isDefaultRate(1.0));
        $this->assertTrue(OrderCurrencyService::isDefaultRate(1.00000000));
        $this->assertFalse(OrderCurrencyService::isDefaultRate(61.61429452));
        $this->assertFalse(OrderCurrencyService::isDefaultRate(0.02100927));
    }
}
