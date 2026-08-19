<?php

namespace App\Support\Fulfilment;

/**
 * One line per product, not one line per unit.
 *
 * Marketplaces disagree about what an order item is. Lazada emits one row per
 * UNIT on several payload shapes, so a customer buying three of the same cable
 * arrives as three identical rows; Shopee and TikTok usually send one row with
 * a quantity. A packer reading "3 x Qable TS50" picks correctly and a packer
 * reading the same cable three times counts by hand.
 *
 * They also disagree about field names for the same fact - name / item_name /
 * product_name, sku / seller_sku / SellerSku / SellerSKU / Sku - which is why
 * every alias is spelled out rather than guessed at.
 *
 * This was inline in the Lazada panel view and is duplicated, with drift, in
 * the Shopee and TikTok panels. It moved here when the packed-parcel modal
 * needed the same answer and the alternative was a fourth copy. Converting the
 * other two panels is mechanical and deliberately not done in the same change
 * as the modal, so a regression in either has one obvious cause.
 */
class OrderItemGrouper
{
    /**
     * Variation strings that mean "this product has no variation". They arrive
     * as literal text from the marketplace rather than as an absent field, and
     * printing "Variation: null" on a picking list is worse than printing
     * nothing.
     */
    private const EMPTY_VARIATIONS = ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--'];

    /**
     * @param  array<int, mixed>  $items  raw order item rows, in any of the shapes above
     * @return array<int, array{name: mixed, sku: mixed, variation: string, quantity: int, image: mixed, item_price: mixed, currency: string}>
     */
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

            // A missing or nonsense quantity is one unit, never zero. An order
            // line that exists describes something a packer has to put in a
            // box, and a zero would drop it out of the count entirely.
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

            // One unit of a pair can carry the photo and the other not. The
            // first non-empty image wins, so a grouped line is not left
            // imageless because the row that happened to be first was bare.
            if (($grouped[$key]['image'] ?? '') === '' && $image !== '') {
                $grouped[$key]['image'] = $image;
            }
        }

        return array_values($grouped);
    }

    /**
     * Total units in an order, which is not the number of lines.
     *
     * @param  array<int, array{quantity?: int}>  $groupedItems
     */
    public static function unitCount(array $groupedItems): int
    {
        $total = 0;

        foreach ($groupedItems as $item) {
            $total += max(0, (int) ($item['quantity'] ?? 0));
        }

        return $total;
    }
}
