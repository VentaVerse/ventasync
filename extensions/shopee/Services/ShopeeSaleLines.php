<?php

namespace Extensions\shopee\Services;

/**
 * A Shopee order's sale, line by line, as Shopee reports it.
 *
 * Built from the escrow detail's order_income once Shopee has settled the
 * order: Shopee's own price, discount, voucher, coin and shipping figures, and
 * its buyer total. Before settlement only the items and shipping are known.
 * Values are in the order's currency; lines that are zero are left out.
 */
final class ShopeeSaleLines
{
    private const LINES = [
        'seller_discount' => [['order_seller_discount', 'seller_discount'], 'Seller discount', -1],
        'shopee_discount' => [['shopee_discount'], 'Shopee discount', -1],
        'voucher' => [['voucher_from_seller'], 'Seller voucher', -1],
        'platform_voucher' => [['voucher_from_shopee'], 'Shopee voucher', -1],
        'coins' => [['coins'], 'Coins', -1],
        'shipping' => [['buyer_paid_shipping_fee'], 'Shipping paid by buyer', 1],
        'buyer_transaction_fee' => [['buyer_transaction_fee'], 'Transaction fee paid by buyer', 1],
    ];

    /**
     * @param  array<string, mixed>  $income  order_income from the escrow detail, or [] when not settled yet
     * @return list<array{code: string, title: string, value: float}>
     */
    public static function lines(array $income, float $itemsSubtotal, float $shipping): array
    {
        $amount = fn (string $key): float => abs((float) ($income[$key] ?? 0));

        if (! isset($income['order_original_price']) && ! isset($income['buyer_total_amount'])) {
            $lines = [['code' => 'sub_total', 'title' => 'Items', 'value' => $itemsSubtotal]];
            if ($shipping != 0.0) {
                $lines[] = ['code' => 'shipping', 'title' => 'Shipping', 'value' => $shipping];
            }
            $lines[] = ['code' => 'total', 'title' => 'Total', 'value' => $itemsSubtotal + $shipping];

            return $lines;
        }

        $lines = [[
            'code' => 'sub_total',
            'title' => 'Original price',
            'value' => isset($income['order_original_price']) ? $amount('order_original_price') : $itemsSubtotal,
        ]];

        foreach (self::LINES as $code => [$keys, $title, $sign]) {
            $value = 0.0;
            foreach ($keys as $key) {
                if ($amount($key) > 0) {
                    $value = $amount($key);
                    break;
                }
            }
            if ($value > 0) {
                $lines[] = ['code' => $code, 'title' => $title, 'value' => $sign * $value];
            }
        }

        $lines[] = [
            'code' => 'total',
            'title' => 'Buyer total',
            'value' => isset($income['buyer_total_amount']) ? $amount('buyer_total_amount') : array_sum(array_column($lines, 'value')),
        ];

        return $lines;
    }
}
