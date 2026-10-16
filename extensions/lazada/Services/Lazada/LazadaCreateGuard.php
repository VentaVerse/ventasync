<?php

namespace Extensions\Lazada\Services\Lazada;

use Extensions\Lazada\Models\LazadaListing;

/**
 * Before a push creates a Lazada listing, Lazada is asked whether the product already has one.
 * A stored "gone" mark can be wrong, so the push never creates on it alone: it creates only
 * when Lazada confirms the listing number is gone and no listing carries the product's SKUs.
 */
final class LazadaCreateGuard
{
    public function __construct(private LazadaLiveListing $live, private LazadaLinkCheck $check)
    {
    }

    /**
     * @return array{verdict: 'existing'|'create'|'refuse', item_id: ?string, message: ?string}
     */
    public function check(object $store, array $creds, int $productId, ?LazadaListing $listing): array
    {
        $known = trim((string) ($listing?->lazada_item_id ?? ''));
        if ($known !== '') {
            $byNumber = $this->live->itemOnLazada($store, $creds, $known);
            if ($byNumber['answer'] === 'yes') {
                return ['verdict' => 'existing', 'item_id' => $known, 'message' => null];
            }
            if ($byNumber['answer'] === 'unknown') {
                return ['verdict' => 'refuse', 'item_id' => null, 'message' => 'Lazada did not say whether listing ' . $known . ' still exists (' . rtrim($byNumber['said'], '.') . '), so nothing was sent. Try again.'];
            }
        }

        $skus = $this->check->catalogSkusByProduct([$productId])[$productId] ?? [];
        if ($skus === []) {
            return ['verdict' => 'create', 'item_id' => null, 'message' => null];
        }
        try {
            $found = $this->live->itemsBySkus($store, $creds, $skus);
        } catch (\RuntimeException $e) {
            return ['verdict' => 'refuse', 'item_id' => null, 'message' => 'Lazada did not say whether it already holds this product (' . rtrim($e->getMessage(), '.') . '), so nothing was sent. Try again.'];
        }

        $hit = null;
        foreach ($skus as $sku) {
            $row = $found[strtolower($sku)] ?? null;
            if ($row !== null && ($row['status'] ?? '') !== 'deleted') {
                $hit = (string) $row['item_id'];
                break;
            }
        }
        if ($hit === null) {
            return ['verdict' => 'create', 'item_id' => null, 'message' => null];
        }

        $holder = LazadaListing::query()->withoutGlobalScope('lazadaStore')
            ->where('store_id', (int) $store->id)
            ->where('lazada_item_id', $hit)->where('product_id', '!=', $productId)
            ->whereNull('lazada_deleted_at')->whereNull('unlinked_at')
            ->value('product_id');
        if ($holder !== null) {
            return ['verdict' => 'refuse', 'item_id' => $hit, 'message' => "Lazada item {$hit} already carries this product's SKU but is linked to Master Catalog product #{$holder}, so nothing was sent."];
        }

        return ['verdict' => 'existing', 'item_id' => $hit, 'message' => null];
    }

    /** Points the listing at the item Lazada holds, so the push updates it instead of listing the product again. */
    public static function adopt(LazadaListing $listing, string $itemId): string
    {
        $from = (string) ($listing->lazada_item_id ?? '');
        $listing->forceFill(['lazada_item_id' => $itemId, 'lazada_deleted_at' => null, 'unlinked_at' => null])->save();
        \App\Services\ActivityLogger::log(
            'updated', 'Lazada Product', (int) $listing->product_id,
            'Push found Lazada item ' . $itemId . ' already holding this product, so it updated it instead of listing again.',
            ['reason' => 'the push asked Lazada before creating', 'item_id' => $itemId, 'previous_item_id' => $from !== '' ? $from : null],
            'user'
        );

        return 'Lazada already has this product as item ' . $itemId . ', so it was updated, not listed again.';
    }
}
