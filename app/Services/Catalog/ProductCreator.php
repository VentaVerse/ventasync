<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Manufacturer;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductToCategory;
use App\Services\ActivityLogger;
use App\Services\StockHistoryLogger;
use App\Support\Catalog\DescriptionHtml;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductCreator
{
    public const MAX_IMAGES = 9;

    public function create(array $data, ?\Closure $variations = null, ?\Closure $images = null, array $by = []): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::transaction(function () use ($data, $variations, $images, $pfx, $langId) {
            $p = new Product();

            $p->model = trim((string) ($data['model'] ?? ''));
            $p->sku = (string) ($data['sku'] ?? '');
            $p->upc = '';
            $p->ean = '';
            $p->jan = '';
            $p->isbn = '';
            $p->mpn = '';
            $p->location = '';

            $p->quantity = (int) ($data['quantity'] ?? 0);
            $p->reorder_level = (int) ($data['reorder_level'] ?? 0);
            $p->weight = (float) ($data['weight'] ?? 0);
            $p->length = (float) ($data['length'] ?? 0);
            $p->width = (float) ($data['width'] ?? 0);
            $p->height = (float) ($data['height'] ?? 0);
            $p->stock_status_id = 0;
            $p->image = null;
            $p->manufacturer_id = (int) ($data['manufacturer_id'] ?? 0);
            $p->shipping = 1;
            $p->status = (int) ($data['status'] ?? 1);

            $p->price = (float) $data['price'];
            $p->cost_amount = (float) ($data['cost_amount'] ?? 0);
            $p->cost_percentage = (float) ($data['cost_percentage'] ?? 0);
            $p->cost_additional = (float) ($data['cost_additional'] ?? 0);
            $p->cost = $p->cost_amount + ($p->cost_percentage / 100 * $p->price) + $p->cost_additional;
            $p->points = 0;
            $p->tax_class_id = 0;

            $da = $p->date_available;
            if (! $da || $da === '0000-00-00') {
                $p->date_available = now()->toDateString();
            }

            $p->save();
            $id = (int) $p->product_id;

            DB::table($pfx . 'product_description')->insert([
                'product_id' => $id,
                'language_id' => $langId,
                'name' => (string) $data['name'],
                'description' => DescriptionHtml::store((string) ($data['description'] ?? '')),
                'meta_title' => (string) $data['name'],
                'meta_description' => '',
                'meta_keyword' => '',
                'tag' => '',
            ]);

            foreach (array_values(array_unique(array_filter(array_map('intval', (array) ($data['category_ids'] ?? []))))) as $catId) {
                ProductToCategory::create(['product_id' => $id, 'category_id' => $catId]);
            }

            if ($variations) {
                $variations($id);
            }

            $paths = $images ? $images($id) : array_values((array) ($data['image_paths'] ?? []));
            if ($images || $paths !== []) {
                $this->saveImages($id, $paths);
            }

            return $id;
        });

        $name = (string) $data['name'];
        $sku = (string) ($data['sku'] ?? '');
        $finalQty = (int) DB::table($pfx . 'product')->where('product_id', $productId)->value('quantity');
        $this->warehouse($productId, $finalQty);

        if (($by['source'] ?? 'user') === 'api') {
            $actor = (string) ($by['actor'] ?? 'An API application');
            $reason = (string) ($by['reason'] ?? '');
            ActivityLogger::log('created', 'Product', $productId,
                $actor . ' created ' . $name . ' (SKU: ' . $sku . '). Reason: ' . $reason,
                ['actor' => $actor, 'reason' => $reason, 'sku' => $sku],
                'api');
            if ($finalQty > 0) {
                StockHistoryLogger::log(
                    productId: $productId, optionValueId: null, orderId: null, type: 'set',
                    qtyBefore: 0, qtyAfter: $finalQty, source: 'api',
                    note: $actor . ' created the product with ' . $finalQty . ' in stock. Reason: ' . $reason,
                );
            }
        } else {
            ActivityLogger::log('created', 'Product', $productId, $name . ' (SKU: ' . $sku . ')');
            if ($finalQty > 0) {
                StockHistoryLogger::log(
                    productId: $productId, optionValueId: null, orderId: null, type: 'set',
                    qtyBefore: 0, qtyAfter: $finalQty, source: 'manual',
                    note: "Product created: initial stock $finalQty",
                );
            }
        }

        return $productId;
    }

    public function resolveManufacturerId(?int $manufacturerId, ?string $manufacturerName): int
    {
        $manufacturerId = (int) ($manufacturerId ?? 0);
        if ($manufacturerId > 0) {
            return $manufacturerId;
        }

        $name = trim((string) ($manufacturerName ?? ''));
        if ($name === '') {
            return 0;
        }

        $row = Manufacturer::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name, 'UTF-8')])
            ->first(['manufacturer_id']);

        return $row ? (int) $row->manufacturer_id : 0;
    }

    public function resolveCategoryId(?int $categoryId, ?string $categoryName): int
    {
        $categoryId = (int) ($categoryId ?? 0);
        if ($categoryId > 0) {
            return $categoryId;
        }

        $name = trim((string) ($categoryName ?? ''));
        if ($name === '') {
            return 0;
        }

        $last = trim(preg_split('/\s*>\s*/', $name)[-1] ?? $name);

        $row = DB::table(config('catalog.prefix') . 'category_description')
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($last, 'UTF-8')])
            ->first(['category_id']);

        return $row ? (int) $row->category_id : 0;
    }

    public function imageFolder(int $manufacturerId): string
    {
        $disk = Storage::disk('public');

        if ($manufacturerId > 0) {
            $m = Manufacturer::query()->where('manufacturer_id', $manufacturerId)->first(['name']);
            if ($m && $m->name) {
                $name = (string) $m->name;
                $slug = Str::slug($name);

                if ($disk->exists('catalog/' . $name)) {
                    return 'catalog/' . $name;
                }
                if ($slug !== '' && $disk->exists('catalog/' . $slug)) {
                    return 'catalog/' . $slug;
                }

                $nameLower = mb_strtolower($name);
                $slugLower = mb_strtolower($slug);
                foreach ($disk->directories('catalog') as $dir) {
                    $folderLower = mb_strtolower(basename($dir));
                    if ($folderLower === $nameLower || $folderLower === $slugLower) {
                        return 'catalog/' . basename($dir);
                    }
                }

                return 'catalog/' . $name;
            }
        }

        return 'catalog/_no_manufacturer_';
    }

    public function saveImages(int $productId, array $paths): void
    {
        $pfx = (string) config('catalog.prefix');

        DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => $paths[0] ?? '']);

        DB::table($pfx . 'product_image')->where('product_id', $productId)->delete();
        foreach (array_values(array_slice($paths, 1)) as $sort => $img) {
            DB::table($pfx . 'product_image')->insert([
                'product_id' => $productId,
                'image' => $img,
                'sort_order' => $sort,
            ]);
        }
    }

    private function warehouse(int $productId, int $quantity): void
    {
        if (! class_exists(\Extensions\warehousing\Services\WarehouseStockService::class)) {
            return;
        }

        $warehouse = \Extensions\warehousing\Services\WarehouseStockService::getDefaultWarehouse();
        if (! $warehouse) {
            return;
        }

        \Extensions\warehousing\Services\WarehouseStockService::getOrCreateInventory($warehouse->id, $productId, 0)
            ->update(['quantity' => $quantity]);
    }
}
