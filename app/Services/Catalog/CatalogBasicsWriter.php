<?php

namespace App\Services\Catalog;

use App\Integrations\Listings\CatalogCopy;
use App\Services\ActivityLogger;
use App\Support\Catalog\DescriptionHtml;
use App\Support\Catalog\ProductImages;
use Illuminate\Support\Facades\DB;

/**
 * Writes the catalog product fields a store's comparison panel shows: name,
 * description, photos, price, weight and size. Only a field that actually
 * changed is written, and the product is marked modified only then, so a
 * save that changed nothing on the catalog raises no Catalog change anywhere.
 */
final class CatalogBasicsWriter
{
    /**
     * @param  array{title?: string, description?: string, photos?: list<string>, price?: float, weight?: float, length?: float, width?: float, height?: float}  $fields
     * @return list<string>  the fields written
     */
    public function write(int $productId, array $fields): array
    {
        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');

        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first();
        if ($product === null) {
            return [];
        }
        $words = DB::table($pfx . 'product_description')->where('product_id', $productId)->where('language_id', $lang)->first();

        $changes = [];
        $productUpdate = [];
        $wordsUpdate = [];

        $title = trim((string) ($fields['title'] ?? ''));
        $oldTitle = CatalogCopy::title((string) ($words->name ?? ''));
        if ($title !== '' && $title !== $oldTitle) {
            $wordsUpdate['name'] = $title;
            $wordsUpdate['meta_title'] = $title;
            $changes['name'] = ['old' => $oldTitle, 'new' => $title];
        }

        if (array_key_exists('description', $fields)) {
            $html = DescriptionHtml::store((string) $fields['description']);
            if (trim($html) !== trim((string) ($words->description ?? ''))) {
                $wordsUpdate['description'] = $html;
                $changes['description'] = ['old' => null, 'new' => 'changed'];
            }
        }

        $photos = null;
        if (array_key_exists('photos', $fields)) {
            $photos = ProductImages::acceptable((array) $fields['photos']);
            if ($photos === ProductImages::paths($productId)) {
                $photos = null;
            } else {
                $changes['images'] = ['old' => null, 'new' => count($photos) . ' photos'];
            }
        }

        if (isset($fields['price']) && abs((float) $fields['price'] - (float) $product->price) > 0.00001) {
            $price = (float) $fields['price'];
            $productUpdate['price'] = $price;
            $productUpdate['cost'] = (float) ($product->cost_amount ?? 0)
                + ((float) ($product->cost_percentage ?? 0) / 100 * $price)
                + (float) ($product->cost_additional ?? 0);
            $changes['price'] = ['old' => (float) $product->price, 'new' => $price];
        }

        foreach (['weight', 'length', 'width', 'height'] as $key) {
            if (isset($fields[$key]) && (float) $fields[$key] > 0 && abs((float) $fields[$key] - (float) ($product->{$key} ?? 0)) > 0.0005) {
                $productUpdate[$key] = (float) $fields[$key];
                $changes[$key] = ['old' => (float) ($product->{$key} ?? 0), 'new' => (float) $fields[$key]];
            }
        }

        if ($changes === []) {
            return [];
        }

        DB::transaction(function () use ($pfx, $lang, $productId, $words, $wordsUpdate, $productUpdate, $photos) {
            if ($wordsUpdate !== []) {
                if ($words !== null) {
                    DB::table($pfx . 'product_description')->where('product_id', $productId)->where('language_id', $lang)->update($wordsUpdate);
                } else {
                    DB::table($pfx . 'product_description')->insert($wordsUpdate + [
                        'product_id' => $productId, 'language_id' => $lang,
                        'name' => $wordsUpdate['name'] ?? '', 'description' => $wordsUpdate['description'] ?? '',
                        'meta_title' => $wordsUpdate['meta_title'] ?? '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
                    ]);
                }
            }
            if ($photos !== null) {
                app(ProductCreator::class)->saveImages($productId, $photos);
            }
            DB::table($pfx . 'product')->where('product_id', $productId)->update($productUpdate + ['date_modified' => now()]);
        });

        $name = $wordsUpdate['name'] ?? CatalogCopy::title((string) ($words->name ?? ''));
        ActivityLogger::log('updated', 'Product', $productId, $name . ' (SKU: ' . ($product->sku ?? '') . ')', $changes);

        return array_keys($changes);
    }
}
