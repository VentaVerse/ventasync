<?php

namespace Extensions\shopee\Services;

use App\Services\Orders\Breakdown\OrderBreakdown;
use App\Support\Money;

/**
 * A Shopee order's income as Seller Center shows it, from the escrow detail
 * Shopee returns once it has worked out the payout. The bottom line is
 * Shopee's escrow amount; a deduction Shopee adds that is not listed here
 * still shows, as the not-itemised line.
 */
final class ShopeeOrderBreakdown
{
    /** @param  array<string, mixed>  $income  the escrow detail's order_income */
    public static function from(array $income): ?OrderBreakdown
    {
        if (! isset($income['escrow_amount'])) {
            return null;
        }
        $a = fn (string $key): float => abs(Money::parse($income[$key] ?? 0));
        $optional = fn (array $rows): array => array_values(array_filter($rows, fn (array $row) => $row[1] != 0.0));

        $productPrice = $a('order_selling_price') ?: ($a('order_discounted_price') ?: $a('original_price'));

        return (new OrderBreakdown('Order income'))
            ->group('Merchandise Subtotal', array_merge(
                [['Product Price', $productPrice]],
                $optional([
                    ['Refund Amount to Buyer', -($a('seller_return_refund') + $a('drc_adjustable_refund'))],
                    ['Voucher Sponsored by Seller', -$a('voucher_from_seller')],
                    ['Coins Cashback Sponsored by Seller', -$a('seller_coin_cash_back')],
                ]),
            ))
            ->group('Estimated Shipping Subtotal', array_merge(
                [
                    ['Shipping Fee Paid by Buyer', $a('buyer_paid_shipping_fee')],
                    ['Estimated Shipping Fee Charged by Logistic Provider', -$a('actual_shipping_fee')],
                    ['Estimated Shipping Fee Rebate from Shopee', $a('shopee_shipping_rebate')],
                ],
                $optional([
                    ['Shipping Fee Discount from 3PL', $a('shipping_fee_discount_from_3pl')],
                    ['Seller Shipping Discount', -$a('seller_shipping_discount')],
                ]),
            ))
            ->group('Fees & Charges', [
                ['Commission Fee', -$a('commission_fee')],
                ['Service Fee', -$a('service_fee')],
                ['Transaction Fee', -$a('seller_transaction_fee')],
                ['Withholding Tax', -$a('withholding_tax')],
            ])
            ->total('Estimated Order Income', Money::parse($income['escrow_amount']));
    }
}
