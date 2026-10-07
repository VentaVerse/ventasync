<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Product;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductPriceWriter
{
    public function set(int $productId, ?string $variationSku, float $price, string $reason, string $actor): array
    {
        if ($price < 0) {
            throw new RuntimeException('A price cannot be negative.');
        }

        $product = Product::query()->find($productId);
        if (! $product) {
            throw new RuntimeException('Product ' . $productId . ' does not exist.');
        }

        $sku = trim((string) $variationSku);

        return $sku === ''
            ? $this->setParent($product, $price, $reason, $actor)
            : $this->setVariation($product, $sku, $price, $reason, $actor);
    }

    private function setParent(Product $product, float $price, string $reason, string $actor): array
    {
        $before = (float) $product->price;

        DB::transaction(function () use ($product, $price) {
            $product->forceFill(['price' => $price, 'date_modified' => now()])->save();
        });

        $this->log($product, 'the price of ' . $this->named($product), (string) ($product->sku ?? ''), $before, $price, $reason, $actor);

        return ['target' => 'product', 'sku' => (string) ($product->sku ?? ''), 'before' => $before, 'after' => $price];
    }

    private function setVariation(Product $product, string $sku, float $price, string $reason, string $actor): array
    {
        $pfx = (string) config('catalog.prefix');
        $needle = strtolower($sku);

        $combo = DB::table('product_option_combinations')
            ->where('product_id', $product->product_id)
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$needle])
            ->first(['id', 'absolute_price']);

        $pov = DB::table($pfx . 'product_option_value')
            ->where('product_id', $product->product_id)
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$needle])
            ->first(['product_option_value_id', 'absolute_price']);

        if (! $combo && ! $pov) {
            throw new RuntimeException(
                'Product ' . $product->product_id . ' has no variation with SKU ' . $sku
                . '. Read the product first and use one of the SKUs it lists.'
            );
        }

        $before = (float) ($combo->absolute_price ?? $pov->absolute_price ?? 0);

        DB::transaction(function () use ($combo, $pov, $pfx, $price) {
            if ($combo) {
                DB::table('product_option_combinations')->where('id', $combo->id)
                    ->update(['absolute_price' => $price, 'updated_at' => now()]);
            }
            if ($pov) {
                DB::table($pfx . 'product_option_value')->where('product_option_value_id', $pov->product_option_value_id)
                    ->update(['absolute_price' => $price]);
            }
        });

        Product::query()->whereKey($product->product_id)->update(['date_modified' => now()]);

        $this->log($product, 'the price of variation ' . $sku . ' of ' . $this->named($product), $sku, $before, $price, $reason, $actor);

        return ['target' => 'variation', 'sku' => $sku, 'before' => $before, 'after' => $price];
    }

    private function named(Product $product): string
    {
        $name = \App\Integrations\Listings\CatalogCopy::title((string) DB::table(config('catalog.prefix') . 'product_description')
            ->where('product_id', $product->product_id)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->value('name'));

        return ($name !== '' ? $name : 'product ' . $product->product_id) . ' (SKU: ' . (string) ($product->sku ?? '') . ')';
    }

    private function log(Product $product, string $what, string $sku, float $before, float $after, string $reason, string $actor): void
    {
        ActivityLogger::log(
            'updated',
            'Product',
            (int) $product->product_id,
            $actor . ' set ' . $what . ' from ' . number_format($before, 2) . ' to ' . number_format($after, 2) . '. Reason: ' . $reason,
            [
                'actor' => $actor,
                'reason' => $reason,
                'product' => $this->named($product),
                'sku' => $sku,
                'price_before' => $before,
                'price_after' => $after,
            ],
            'api'
        );
    }
}
