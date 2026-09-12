<?php

namespace Extensions\tiktok\Services;

/**
 * A TikTok Shop order's sale, line by line, from the payment block TikTok
 * returns with the order: original prices, the discounts split by who funded
 * them, buyer-paid fees, tax, and TikTok's own total. Without a payment block
 * only the items and shipping are known. Values are in the order's currency;
 * lines that are zero are left out.
 */
final class TikTokSaleLines
{
    private const LINES = [
        // Item markdown (seller_discount, platform_discount) is the gap from the
        // original price to sub_total; it is already in sub_total below and the
        // store does not itemise it, so it is intentionally not a line here.
        'shipping' => ['original_shipping_fee', 'Original shipping fee', 1],
        'shipping_discount_platform' => ['shipping_fee_platform_discount', 'Shipping discount by TikTok', -1],
        'shipping_discount_seller' => ['shipping_fee_seller_discount', 'Shipping discount by seller', -1],
        'shipping_discount_cofunded' => ['shipping_fee_cofunded_discount', 'Shipping discount, co-funded', -1],
        'payment_discount' => ['payment_platform_discount', 'Payment discount', -1],
        'handling_fee' => ['handling_fee', 'Handling fee', 1],
        'item_insurance' => ['item_insurance_fee', 'Item insurance', 1],
        'tax' => ['tax', 'Tax', 1],
    ];

    /**
     * @param  array<string, mixed>  $payment  the order's payment block, or [] when absent
     * @return list<array{code: string, title: string, value: float}>
     */
    public static function lines(array $payment, float $itemsSubtotal, float $shipping): array
    {
        $amount = fn (string $key): float => abs((float) ($payment[$key] ?? 0));

        if (! isset($payment['original_total_product_price']) && ! isset($payment['total_amount'])) {
            $lines = [['code' => 'sub_total', 'title' => 'Items', 'value' => $itemsSubtotal]];
            if ($shipping != 0.0) {
                $lines[] = ['code' => 'shipping', 'title' => 'Shipping', 'value' => $shipping];
            }
            $lines[] = ['code' => 'total', 'title' => 'Total', 'value' => $itemsSubtotal + $shipping];

            return $lines;
        }

        // Merchandise subtotal = sub_total (product price after item discounts),
        // the figure the store shows; fall back to the original price, then the
        // summed line items.
        $subtotal = $itemsSubtotal;
        foreach (['sub_total', 'original_total_product_price'] as $key) {
            if (isset($payment[$key]) && $amount($key) > 0) {
                $subtotal = $amount($key);
                break;
            }
        }
        $lines = [[
            'code' => 'sub_total',
            'title' => 'Merchandise subtotal',
            'value' => $subtotal,
        ]];

        foreach (self::LINES as $code => [$key, $title, $sign]) {
            if ($amount($key) > 0) {
                $lines[] = ['code' => $code, 'title' => $title, 'value' => $sign * $amount($key)];
            }
        }

        $lines[] = [
            'code' => 'total',
            'title' => 'Total',
            'value' => isset($payment['total_amount']) ? $amount('total_amount') : array_sum(array_column($lines, 'value')),
        ];

        return $lines;
    }
}
