<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaListingVariation extends Model
{
    protected $table = 'ch_lazada_listing_variations';

    protected $fillable = [
        'lazada_listing_id', 'seller_sku', 'sku_id', 'shop_sku', 'product_variation_value_id',
        'price', 'quantity',
    ];

    /**
     * Keeps what Lazada holds for one variation: its SKU, SKU id and shop SKU.
     *
     * A listing holds one row per variation. Saving the listing page before the first push makes that row
     * with a price and no SKU, so the row is found by its SKU or, failing that, by its variation.
     */
    public static function remember(int $listingId, string $sellerSku, ?int $variationValueId, array $values): void
    {
        $row = self::query()->where('lazada_listing_id', $listingId)->where('seller_sku', $sellerSku)->first()
            ?? ($variationValueId !== null
                ? self::query()->where('lazada_listing_id', $listingId)->where('product_variation_value_id', $variationValueId)->first()
                : null);

        $attributes = ['seller_sku' => $sellerSku] + $values;
        if ($variationValueId !== null && ($row === null || (int) $row->product_variation_value_id !== $variationValueId)) {
            $held = self::query()->where('lazada_listing_id', $listingId)->where('product_variation_value_id', $variationValueId)
                ->when($row !== null, fn ($q) => $q->where('id', '!=', $row->id))->exists();
            if (! $held) {
                $attributes['product_variation_value_id'] = $variationValueId;
            }
        }

        try {
            $row !== null ? $row->fill($attributes)->save() : self::query()->create(['lazada_listing_id' => $listingId] + $attributes);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Lazada: a variation\'s SKU id could not be kept', [
                'lazada_listing_id' => $listingId, 'seller_sku' => $sellerSku, 'error' => $e->getMessage(),
            ]);
        }
    }
}
