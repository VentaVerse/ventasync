<?php

namespace Extensions\lazada\Services;

/**
 * A Lazada order's sale, line by line, as Lazada reports it on the order:
 * price, shipping fee, and the vouchers and shipping discounts split by who
 * funded them. Lazada sends no single amount the buyer paid per order, so the
 * total is the sum of these lines. Values are in the order's currency; lines
 * that are zero are left out.
 */
final class LazadaSaleLines
{
    private const DISCOUNTS = [
        'shipping_discount_platform' => ['shipping_fee_discount_platform', 'Shipping discount by Lazada'],
        'shipping_discount_seller' => ['shipping_fee_discount_seller', 'Shipping discount by seller'],
        'platform_voucher' => ['voucher_platform', 'Voucher by Lazada'],
        'voucher' => ['voucher_seller', 'Voucher by seller'],
    ];

    /**
     * @param  array<string, mixed>  $order  the order as Lazada returned it
     * @return list<array{code: string, title: string, value: float}>
     */
    public static function lines(array $order, float $itemsSubtotal, float $shipping): array
    {
        $amount = fn (string $key): float => abs((float) str_replace(',', '', (string) ($order[$key] ?? 0)));

        $lines = [[
            'code' => 'sub_total',
            'title' => 'Price',
            'value' => isset($order['price']) ? $amount('price') : $itemsSubtotal,
        ]];

        $shippingFee = isset($order['shipping_fee_original']) ? $amount('shipping_fee_original') : $shipping;
        if ($shippingFee > 0) {
            $lines[] = ['code' => 'shipping', 'title' => 'Shipping fee', 'value' => $shippingFee];
        }

        $split = false;
        foreach (self::DISCOUNTS as $code => [$key, $title]) {
            if ($amount($key) > 0) {
                $lines[] = ['code' => $code, 'title' => $title, 'value' => -$amount($key)];
                $split = $split || str_contains($key, 'voucher');
            }
        }
        // Older orders carry one combined voucher figure instead of the split.
        if (! $split && $amount('voucher') > 0) {
            $lines[] = ['code' => 'voucher', 'title' => 'Voucher', 'value' => -$amount('voucher')];
        }

        $lines[] = ['code' => 'total', 'title' => 'Total', 'value' => array_sum(array_column($lines, 'value'))];

        return $lines;
    }
}
