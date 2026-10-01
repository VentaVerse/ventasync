<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Product;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SET ONE PRICE, AND SAY WHAT CHANGED (#177 phase 1).
 *
 * The assistant may change a price, so the change has to be as legible
 * afterwards as a human's would be: one product or one variation per call,
 * a stated reason, the before and the after returned to the caller, and an
 * activity row naming who asked and why.
 *
 * It writes the same columns the product form writes and nothing else:
 * `product.price` for the parent, `absolute_price` on the combination and
 * on its option value for a variation - the pair the form keeps in step,
 * because the combination is what a push reads and the option value is
 * what the older single-axis screens read.
 *
 * What it deliberately does NOT do is push. A marketplace price is the
 * catalog price run through the listing's rule at push time, so a price
 * changed here reaches a channel on its next push and not before. The
 * caller is told that in words rather than left to assume either way.
 */
final class ProductPriceWriter
{
    /**
     * @param  string|null  $variationSku  null for the product's own price
     * @return array{target: string, sku: string, before: float, after: float}
     *
     * @throws RuntimeException with a sentence fit to show the caller
     */
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

    /** @return array{target: string, sku: string, before: float, after: float} */
    private function setParent(Product $product, float $price, string $reason, string $actor): array
    {
        $before = (float) $product->price;

        DB::transaction(function () use ($product, $price) {
            $product->forceFill(['price' => $price, 'date_modified' => now()])->save();
        });

        $this->log($product, 'the price of ' . $this->named($product), (string) ($product->sku ?? ''), $before, $price, $reason, $actor);

        return ['target' => 'product', 'sku' => (string) ($product->sku ?? ''), 'before' => $before, 'after' => $price];
    }

    /**
     * A variation is addressed by its SKU, which is what every channel
     * and every push already keys on, so the assistant names the same
     * thing an operator would read off the screen.
     *
     * @return array{target: string, sku: string, before: float, after: float}
     */
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

        // The catalog product itself moved for every channel that reads it.
        Product::query()->whereKey($product->product_id)->update(['date_modified' => now()]);

        $this->log($product, 'the price of variation ' . $sku . ' of ' . $this->named($product), $sku, $before, $price, $reason, $actor);

        return ['target' => 'variation', 'sku' => $sku, 'before' => $before, 'after' => $price];
    }

    /** The product as every other ledger line names it: its name and its SKU. */
    private function named(Product $product): string
    {
        $name = \App\Integrations\Listings\CatalogCopy::title((string) DB::table(config('catalog.prefix') . 'product_description')
            ->where('product_id', $product->product_id)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->value('name'));

        return ($name !== '' ? $name : 'product ' . $product->product_id) . ' (SKU: ' . (string) ($product->sku ?? '') . ')';
    }

    /**
     * The audit row. The application is the actor by name, the product is
     * named, the reason is the assistant's own words, and both prices are
     * kept so the change reads without opening the product. `sku` is the
     * variation's when one was priced, otherwise the product's own.
     */
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
            // Not 'user': no human pressed this, and the ledger must not
            // read as though one did.
            'api'
        );
    }
}
