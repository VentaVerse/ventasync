<?php

namespace App\Support\Fulfilment;

class OrderItemGrouper
{
    private const EMPTY_VARIATIONS = ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--'];

    public static function group(array $items): array
    {
        $grouped = [];

        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowRaw = (isset($row['raw']) && is_array($row['raw'])) ? $row['raw'] : $row;

            $name = $row['name'] ?? $rowRaw['name'] ?? $rowRaw['item_name'] ?? $rowRaw['product_name'] ?? '';
            $sku = $row['sku'] ?? $rowRaw['sku'] ?? $rowRaw['seller_sku'] ?? $rowRaw['SellerSku'] ?? $rowRaw['SellerSKU'] ?? $rowRaw['Sku'] ?? '';

            $variation = (string) ($row['variation'] ?? $rowRaw['variation'] ?? $rowRaw['Variation'] ?? $rowRaw['variant'] ?? '');
            if (in_array(mb_strtolower(trim($variation)), self::EMPTY_VARIATIONS, true)) {
                $variation = '';
            }

            $qty = (int) ($row['quantity'] ?? $rowRaw['quantity'] ?? $rowRaw['qty'] ?? 1);
            if ($qty < 1) {
                $qty = 1;
            }

            $image = $row['image'] ?? $rowRaw['image'] ?? $rowRaw['product_main_image'] ?? $rowRaw['product_image'] ?? '';
            $itemPrice = $row['item_price'] ?? $row['paid_price'] ?? $rowRaw['item_price'] ?? $rowRaw['paid_price'] ?? null;
            $currency = (string) ($rowRaw['currency'] ?? '');

            $key = mb_strtolower(trim((string) $sku))
                .'|'.mb_strtolower(trim((string) $name))
                .'|'.mb_strtolower(trim($variation));

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'name' => $name,
                    'sku' => $sku,
                    'variation' => $variation,
                    'quantity' => $qty,
                    'image' => $image,
                    'item_price' => $itemPrice,
                    'currency' => $currency,
                ];

                continue;
            }

            $grouped[$key]['quantity'] += $qty;

            if (($grouped[$key]['image'] ?? '') === '' && $image !== '') {
                $grouped[$key]['image'] = $image;
            }
        }

        return array_values($grouped);
    }

    public static function unitCount(array $groupedItems): int
    {
        $total = 0;

        foreach ($groupedItems as $item) {
            $total += max(0, (int) ($item['quantity'] ?? 0));
        }

        return $total;
    }
}
