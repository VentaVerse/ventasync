<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Product;
use App\Services\StockHistoryLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductQuantityWriter
{
    private string $source = 'api';

    public function from(string $source): static
    {
        $writer = clone $this;
        $writer->source = $source;

        return $writer;
    }

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
            source: $this->source,
            note: $actor . ' set ' . $what . ', qty ' . $before . ' to ' . $after . '. Reason: ' . $reason,
        );
    }

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
