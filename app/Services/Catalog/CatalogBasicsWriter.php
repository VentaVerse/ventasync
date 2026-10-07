<?php

namespace App\Services\Catalog;

use App\Integrations\Listings\CatalogCopy;
use App\Services\ActivityLogger;
use App\Support\Catalog\DescriptionHtml;
use App\Support\Catalog\ProductImages;
use Illuminate\Support\Facades\DB;

final class CatalogBasicsWriter
{
    public function write(int $productId, array $fields): array
    {
        return array_keys($this->edit($productId, $fields));
    }

    public function edit(int $productId, array $fields, array $by = []): array
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

        $sku = trim((string) ($fields['sku'] ?? ''));
        if ($sku !== '' && $sku !== trim((string) $product->sku)) {
            $productUpdate['sku'] = $sku;
            $changes['sku'] = ['old' => (string) $product->sku, 'new' => $sku];
        }

        if (array_key_exists('status', $fields) && (int) (bool) $fields['status'] !== (int) $product->status) {
            $productUpdate['status'] = (int) (bool) $fields['status'];
            $changes['status'] = ['old' => (bool) $product->status, 'new' => (bool) $fields['status']];
        }

        if (array_key_exists('manufacturer_id', $fields) && (int) $fields['manufacturer_id'] !== (int) $product->manufacturer_id) {
            $productUpdate['manufacturer_id'] = (int) $fields['manufacturer_id'];
            $changes['manufacturer_id'] = ['old' => (int) $product->manufacturer_id, 'new' => (int) $fields['manufacturer_id']];
        }

        $categoryIds = null;
        if (array_key_exists('category_ids', $fields)) {
            $wanted = array_values(array_unique(array_map('intval', (array) $fields['category_ids'])));
            $held = DB::table($pfx . 'product_to_category')->where('product_id', $productId)->orderBy('category_id')->pluck('category_id')->map(fn ($id) => (int) $id)->all();
            $sorted = $wanted;
            sort($sorted);
            if ($sorted !== $held) {
                $categoryIds = $wanted;
                $changes['category_ids'] = ['old' => $held, 'new' => $sorted];
            }
        }

        if ($changes === []) {
            return [];
        }

        DB::transaction(function () use ($pfx, $lang, $productId, $words, $wordsUpdate, $productUpdate, $photos, $categoryIds) {
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
            if ($categoryIds !== null) {
                DB::table($pfx . 'product_to_category')->where('product_id', $productId)->delete();
                foreach ($categoryIds as $categoryId) {
                    DB::table($pfx . 'product_to_category')->insert(['product_id' => $productId, 'category_id' => $categoryId]);
                }
            }
            DB::table($pfx . 'product')->where('product_id', $productId)->update($productUpdate + ['date_modified' => now()]);
        });

        $name = $wordsUpdate['name'] ?? CatalogCopy::title((string) ($words->name ?? ''));
        $label = $name . ' (SKU: ' . ($productUpdate['sku'] ?? $product->sku ?? '') . ')';
        if (isset($by['reason']) && trim((string) $by['reason']) !== '') {
            $label = trim((string) ($by['actor'] ?? '')) . ' edited ' . $label . '. Reason: ' . trim((string) $by['reason']);
        }
        ActivityLogger::log('updated', 'Product', $productId, $label, $changes);

        return $changes;
    }
}
