<?php

namespace Tests\Feature;

use Extensions\lazada\Services\LazadaSaleLines;
use Extensions\shopee\Services\ShopeeSaleLines;
use Extensions\tiktok\Services\TikTokSaleLines;
use Tests\TestCase;

/**
 * Each marketplace's sale is written as the lines it reported, under its own
 * names, with zero lines left out and the marketplace's own total last.
 */
class SaleLinesTest extends TestCase
{
    /** @return list<array{0: string, 1: float}> */
    private function shape(array $lines): array
    {
        return array_map(fn (array $line) => [$line['title'], round($line['value'], 2)], $lines);
    }

    public function test_a_settled_shopee_order_lists_shopees_breakdown_and_its_buyer_total(): void
    {
        $lines = ShopeeSaleLines::lines([
            'order_original_price' => 2600, 'shopee_discount' => 0, 'voucher_from_shopee' => 100,
            'coins' => 20, 'buyer_paid_shipping_fee' => 36, 'buyer_total_amount' => 2516,
        ], 2500, 0);

        $this->assertSame([
            ['Original price', 2600.0],
            ['Shopee voucher', -100.0],
            ['Coins', -20.0],
            ['Shipping paid by buyer', 36.0],
            ['Buyer total', 2516.0],
        ], $this->shape($lines));
    }

    public function test_an_unsettled_shopee_order_lists_the_items_and_shipping_known_so_far(): void
    {
        $this->assertSame([['Items', 500.0], ['Total', 500.0]], $this->shape(ShopeeSaleLines::lines([], 500, 0)));
        $this->assertSame([['Items', 500.0], ['Shipping', 45.0], ['Total', 545.0]], $this->shape(ShopeeSaleLines::lines([], 500, 45)));
    }

    public function test_a_lazada_order_lists_its_vouchers_and_shipping_discounts_by_who_funded_them(): void
    {
        $lines = LazadaSaleLines::lines([
            'price' => '1,299.00', 'shipping_fee_original' => 80, 'shipping_fee_discount_platform' => 80,
            'shipping_fee_discount_seller' => 0, 'voucher_platform' => 50, 'voucher_seller' => 0,
        ], 1299, 0);

        $this->assertSame([
            ['Price', 1299.0],
            ['Shipping fee', 80.0],
            ['Shipping discount by Lazada', -80.0],
            ['Voucher by Lazada', -50.0],
            ['Total', 1249.0],
        ], $this->shape($lines));
    }

    public function test_an_older_lazada_order_with_one_combined_voucher_lists_it_once(): void
    {
        $this->assertSame(
            [['Price', 2400.0], ['Shipping fee', 80.0], ['Voucher', -100.0], ['Total', 2380.0]],
            $this->shape(LazadaSaleLines::lines(['price' => 2400, 'voucher' => 100], 2400, 80))
        );
    }

    public function test_a_tiktok_order_lists_its_payment_breakdown_and_tiktoks_total(): void
    {
        $lines = TikTokSaleLines::lines([
            'original_total_product_price' => '1000', 'seller_discount' => '0', 'platform_discount' => '100',
            'original_shipping_fee' => '50', 'shipping_fee_platform_discount' => '50', 'tax' => '0', 'total_amount' => '900',
        ], 1000, 0);

        $this->assertSame([
            ['Original product price', 1000.0],
            ['TikTok discount', -100.0],
            ['Original shipping fee', 50.0],
            ['Shipping discount by TikTok', -50.0],
            ['Total', 900.0],
        ], $this->shape($lines));
    }

    public function test_every_breakdown_ends_with_a_total_line(): void
    {
        foreach ([
            ShopeeSaleLines::lines([], 10, 0),
            LazadaSaleLines::lines([], 10, 0),
            TikTokSaleLines::lines([], 10, 0),
        ] as $lines) {
            $this->assertSame('total', end($lines)['code']);
            $this->assertSame('sub_total', $lines[0]['code']);
        }
    }
}
