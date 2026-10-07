<?php

namespace Tests\Unit;

use App\Support\PayoutExpectation;
use PHPUnit\Framework\TestCase;

class PayoutExpectationTest extends TestCase
{
    public function test_shopee_pays_its_escrow(): void
    {
        $fees = json_encode(['order_income' => ['escrow_amount' => 156.21, 'commission_fee' => 20]]);

        $this->assertSame(['amount' => 156.21, 'estimated' => false], PayoutExpectation::for('shopee', $fees));
    }

    public function test_a_negative_shopee_escrow_is_kept_as_reported(): void
    {
        $fees = json_encode(['order_income' => ['escrow_amount' => -242]]);

        $this->assertSame(['amount' => -242.0, 'estimated' => false], PayoutExpectation::for('shopee', $fees));
    }

    public function test_lazada_pays_the_sum_of_its_finance_lines_not_paid_total(): void
    {
        $fees = json_encode(['paid_total' => 412, 'transactions' => [
            ['name' => 'Item Price Credit', 'amount' => 412],
            ['name' => 'Commission', 'amount' => -43.28],
            ['name' => 'Payment Fee', 'amount' => -10.08],
        ]]);

        $this->assertSame(['amount' => 358.64, 'estimated' => false], PayoutExpectation::for('lazada', $fees));
    }

    public function test_an_older_lazada_order_sums_its_bucketed_lines(): void
    {
        $fees = json_encode(['paid_total' => 200, 'commission' => -21.02, 'payment_fee' => -5.72, 'shipping_service_cost' => -11.6,
            'other_fees' => ['Item Price Credit' => 200, 'LazCoins Discount Promotion Fee' => -2.24, 'Order Processing Fee' => -5]]);

        $this->assertSame(['amount' => 154.42, 'estimated' => false], PayoutExpectation::for('lazada', $fees));
    }

    public function test_tiktok_pays_its_settlement(): void
    {
        $fees = json_encode(['settlement_amount' => 414.12, 'revenue' => 492]);

        $this->assertSame(['amount' => 414.12, 'estimated' => false], PayoutExpectation::for('tiktok', $fees));
    }

    public function test_before_the_marketplace_reports_the_order_total_stands_in_as_an_estimate(): void
    {
        $this->assertSame(['amount' => 8990.0, 'estimated' => true], PayoutExpectation::for('lazada', null, json_encode(['price' => '8,990.00'])));
        $this->assertSame(['amount' => 500.0, 'estimated' => true], PayoutExpectation::for('shopee', '[]', json_encode(['total_amount' => 500])));
        $this->assertSame(['amount' => null, 'estimated' => false], PayoutExpectation::for('tiktok', null, null));
        $this->assertFalse(PayoutExpectation::reported('lazada', json_encode(['paid_total' => 412])));
    }
}
