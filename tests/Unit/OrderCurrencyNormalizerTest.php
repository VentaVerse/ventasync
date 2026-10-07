<?php

namespace Tests\Unit;

use App\Services\OrderCurrencyNormalizer as N;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OrderCurrencyNormalizerTest extends TestCase
{
    public function test_default_currency_order_is_classified_default(): void
    {
        $this->assertSame(N::CLASS_DEFAULT, N::classify('PHP', 1.0, 'PHP'));
    }

    public function test_default_currency_order_is_default_even_with_odd_rate(): void
    {
        $this->assertSame(N::CLASS_DEFAULT, N::classify('PHP', 0.021, 'PHP'));
    }

    public function test_foreign_order_with_non_unit_rate_is_legacy(): void
    {
        $this->assertSame(N::CLASS_LEGACY, N::classify('USD', 0.02100927, 'PHP'));
    }

    public function test_foreign_order_with_unit_rate_is_lost_rate(): void
    {
        $this->assertSame(N::CLASS_LOST_RATE, N::classify('USD', 1.0, 'PHP'));
    }

    public function test_classification_is_not_hardcoded_to_php(): void
    {
        $this->assertSame(N::CLASS_DEFAULT, N::classify('USD', 1.0, 'USD'));
        $this->assertSame(N::CLASS_LEGACY, N::classify('PHP', 47.598, 'USD'));
    }

    public function test_legacy_order_plan_inverts_rate_and_derives_foreign_total(): void
    {
        $plan = N::planLegacyOrder(6602.08, 0.02100927);

        $this->assertSame(138.7049, $plan['foreign_total']);
        $this->assertSame(47.59803649, $plan['currency_value']);
    }

    public function test_legacy_product_plan_uses_old_rate_before_inversion(): void
    {
        $plan = N::planLegacyProduct(2790.00, 5580.00, 0.02100927);

        $this->assertSame(58.6159, $plan['foreign_price']);
        $this->assertSame(117.2317, $plan['foreign_total']);
    }

    public function test_inverting_twice_would_not_round_trip(): void
    {
        $plan = N::planLegacyProduct(2790.00, 5580.00, 0.02100927);
        $wrong = round(2790.00 * (1 / 0.02100927), 4);

        $this->assertNotEqualsWithDelta($wrong, $plan['foreign_price'], 1.0);
        $this->assertLessThan(100.0, $plan['foreign_price']);
    }

    public function test_legacy_plan_rejects_zero_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        N::planLegacyOrder(100.0, 0.0);
    }
}
