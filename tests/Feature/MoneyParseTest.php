<?php

namespace Tests\Feature;

use App\Support\Money;
use Tests\TestCase;

/**
 * Money::parse must read a marketplace amount in full even when it arrives as a
 * string with a thousands separator - Lazada's finance API sends "-2,251.57",
 * and a bare (float) cast stopped at the comma and read that as -2, which
 * truncated every fee over 1,000 and overstated profit.
 */
class MoneyParseTest extends TestCase
{
    public function test_it_strips_the_thousands_comma_a_marketplace_sends(): void
    {
        $this->assertSame(-2251.57, Money::parse('-2,251.57'));
        $this->assertSame(21526.64, Money::parse('21,526.64'));
        $this->assertSame(1124.10, Money::parse('1,124.10'));
    }

    public function test_it_leaves_plain_and_numeric_values_alone(): void
    {
        $this->assertSame(480.08, Money::parse('480.08'));
        $this->assertSame(480.08, Money::parse(480.08));
        $this->assertSame(100.0, Money::parse(100));
        $this->assertSame(-94.5, Money::parse('-94.50'));
    }

    public function test_it_treats_missing_values_as_zero(): void
    {
        $this->assertSame(0.0, Money::parse(null));
        $this->assertSame(0.0, Money::parse(''));
    }
}
