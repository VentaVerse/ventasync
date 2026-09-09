<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Product;
use App\Services\StockHistoryLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SET ONE QUANTITY, AND KEEP THE PARENT HONEST (#177).
 *
 * The companion to ProductPriceWriter, and it addresses a variation the
 * same way: by its SKU, the code every channel keys on and the one
 * products_get prints. That is not a convenience. The mobile app's
 * quantity endpoint is keyed by option VALUE, which cannot name one
 * variation of a two-axis product - "Red" belongs to Red+Small and
 * Red+Large alike - so it has only ever worked for single-axis products.
 * A SKU names exactly one sellable thing on every product there is.
 *
 * THE PARENT IS NOT A NUMBER ANYONE SETS. On a product with variations,
 * `product.quantity` is the sum of them, and nothing in the database
 * enforces that: code does, here, in the same write. So a variation set
 * through this service always leaves the parent correct, and a caller
 * asking to set the parent DIRECTLY on such a product is refused rather
 * than allowed to write a figure the next variation edit overwrites.
 *
 * Stock lives in `product_option_combinations` - the sellable unit, which
 * is what App\Support\VariationRows reads and what every list in this
 * application shows. `product_option_value.quantity` is the older
 * per-axis copy; it is mirrored, but only for a combination that maps to
 * exactly ONE option value, because a Colour+Size combination owns its own
 * stock and must never be overwritten from a single choice.
 */
final class ProductQuantityWriter
{
    /**
     * @param  string|null  $variationSku  null for the product's own quantity
     * @param  int|null  $newQuantity  the absolute figure to set
     * @param  int|null  $changeBy  a delta to apply instead; exactly one of the two
     * @return array{target: string, sku: string, before: int, after: int, product_before: int, product_after: int}
     *
     * @throws RuntimeException with a sentence fit to show the caller
     */
    public function set(int $productId, ?string $variationSku, ?int $newQuantity, ?int $changeBy, string $reason, string $actor): array
    {
        if (($newQuantity === null) === ($changeBy === null)) {
            throw new RuntimeException('Say either the new quantity or how much to change it by, not both and not neither.');
        }

        $product = Product::query()->find($productId);
        if (! $product) {
            throw new RuntimeException('Product ' . $productId . ' does not exist.');
        }

        $sku = trim((string) $variationSku);
        $hasVariations = DB::table('product_option_combinations')->where('product_id', $productId)->exists();

        if ($sku === '' && $hasVariations) {
            throw new RuntimeException(
                'Product ' . $productId . " has variations, so its own quantity is the sum of theirs and cannot be set "
                . 'directly. Set the variation you mean, by its SKU; the product total follows.'
            );
        }
        if ($sku !== '' && ! $hasVariations) {
            throw new RuntimeException('Product ' . $productId . ' has no variations, so it has no SKU ' . $sku . ' to set.');
        }

        return $sku === ''
            ? $this->setProduct($product, $newQuantity, $changeBy, $reason, $actor)
            : $this->setVariation($product, $sku, $newQuantity, $changeBy, $reason, $actor);
    }

    /** A product with no variations owns its own figure. */
    private function setProduct(Product $product, ?int $newQuantity, ?int $changeBy, string $reason, string $actor): array
    {
        $before = (int) $product->quantity;
        $after = $this->resolve($before, $newQuantity, $changeBy);

        DB::table($this->productTable())->where('product_id', $product->product_id)
            ->update(['quantity' => $after, 'date_modified' => now()]);

        $this->history($product, null, $before, $after, $reason, $actor, 'the product');
        $this->warehouse((int) $product->product_id, 0, $after);

        return [
            'target' => 'product', 'sku' => (string) ($product->sku ?? ''),
            'before' => $before, 'after' => $after,
            'product_before' => $before, 'product_after' => $after,
        ];
    }

    /**
     * One variation, then the parent recomputed from ALL of them - not by
     * adding the delta to the old total, which would carry any drift
     * already in the row forward forever.
     */
    private function setVariation(Product $product, string $sku, ?int $newQuantity, ?int $changeBy, string $reason, string $actor): array
    {
        $needle = strtolower($sku);
        $combo = DB::table('product_option_combinations')
            ->where('product_id', $product->product_id)
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$needle])
            ->first(['id', 'quantity']);

        if (! $combo) {
            throw new RuntimeException(
                'Product ' . $product->product_id . ' has no variation with SKU ' . $sku
                . '. Read the product first and use one of the SKUs it lists.'
            );
        }

        $before = (int) $combo->quantity;
        $after = $this->resolve($before, $newQuantity, $changeBy);
        $parentBefore = (int) $product->quantity;

        // The one option value this combination maps to, when it maps to
        // exactly one: the older per-axis copy and the warehouse row are
        // addressed by that, and a multi-axis combination has no single one.
        $valueIds = DB::table('product_option_combination_values')
            ->where('combination_id', $combo->id)->pluck('product_option_value_id');
        $lonePovId = $valueIds->count() === 1 ? (int) $valueIds->first() : null;

        $parentAfter = DB::transaction(function () use ($combo, $after, $lonePovId, $product) {
            DB::table('product_option_combinations')->where('id', $combo->id)
                ->update(['quantity' => $after, 'updated_at' => now()]);

            if ($lonePovId !== null) {
                DB::table($this->prefix() . 'product_option_value')
                    ->where('product_option_value_id', $lonePovId)
                    ->where('product_id', $product->product_id)
                    ->update(['quantity' => $after]);
            }

            $sum = (int) DB::table('product_option_combinations')
                ->where('product_id', $product->product_id)->sum('quantity');

            DB::table($this->productTable())->where('product_id', $product->product_id)
                ->update(['quantity' => $sum, 'date_modified' => now()]);

            return $sum;
        });

        $this->history($product, $lonePovId, $before, $after, $reason, $actor, 'variation ' . $sku);
        if ($parentBefore !== $parentAfter) {
            $this->history($product, null, $parentBefore, $parentAfter, $reason, $actor, 'the product total');
        }
        if ($lonePovId !== null) {
            $this->warehouse((int) $product->product_id, $lonePovId, $after);
        }

        return [
            'target' => 'variation', 'sku' => $sku,
            'before' => $before, 'after' => $after,
            'product_before' => $parentBefore, 'product_after' => $parentAfter,
        ];
    }

    /** A figure that is never negative, whichever way it was asked for. */
    private function resolve(int $before, ?int $newQuantity, ?int $changeBy): int
    {
        $after = $newQuantity ?? ($before + (int) $changeBy);

        if ($after < 0) {
            throw new RuntimeException(
                'That would leave ' . $after . ' in stock. Stock cannot go below zero here; correct the figure.'
            );
        }

        return $after;
    }

    private function history(Product $product, ?int $povId, int $before, int $after, string $reason, string $actor, string $what): void
    {
        if ($before === $after) {
            return;
        }

        StockHistoryLogger::log(
            productId: (int) $product->product_id,
            optionValueId: $povId,
            orderId: null,
            type: 'set',
            qtyBefore: $before,
            qtyAfter: $after,
            // Not 'user' and not 'mobile': the ledger names what actually
            // did this, so a figure nobody remembers changing can be traced.
            source: 'api',
            note: $actor . ' set ' . $what . ', qty ' . $before . ' to ' . $after . '. Reason: ' . $reason,
        );
    }

    /** Keep the default warehouse's copy in step, where there is one. */
    private function warehouse(int $productId, int $povId, int $quantity): void
    {
        if (! class_exists(\Extensions\warehousing\Services\WarehouseStockService::class)) {
            return;
        }

        $warehouse = \Extensions\warehousing\Services\WarehouseStockService::getDefaultWarehouse();
        if (! $warehouse) {
            return;
        }

        \Extensions\warehousing\Services\WarehouseStockService::getOrCreateInventory($warehouse->id, $productId, $povId)
            ->update(['quantity' => $quantity]);
    }

    private function prefix(): string
    {
        return (string) config('catalog.prefix');
    }

    private function productTable(): string
    {
        return $this->prefix() . 'product';
    }
}
