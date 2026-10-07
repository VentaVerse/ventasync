<?php

namespace App\Http\Controllers\Concerns;

use App\Integrations\Listings\CatalogCopy;
use App\Integrations\Listings\ListingContent;
use App\Integrations\Listings\ListingImages;
use App\Services\Catalog\CatalogBasicsWriter;
use App\Support\BackTo;
use App\Support\Catalog\ProductImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ComparesWithTheCatalog
{
    abstract protected function catalogChangeListing(int $productId): Model;

    abstract protected function catalogChangeFallback(int $productId): string;

    public function catalogChangeSave(Request $request, int $productId)
    {
        $listing = $this->catalogChangeListing($productId);
        $cols = $listing::copyColumns();
        $canCatalog = (bool) $request->user()?->hasPermission('manage_catalog/product');

        $rules = [
            'back' => 'nullable|string|max:2000',
            'listing.title' => 'required|string|max:255',
            'listing.description' => 'nullable|string|max:60000',
            'listing.description_edited' => 'nullable|in:0,1',
            'listing.photos' => 'nullable|string|max:20000',
            'listing.price' => 'nullable|numeric|min:0|max:99999999',
            'listing.weight' => 'nullable|numeric|min:0.001|max:99999',
            'listing.length' => 'nullable|integer|min:1|max:99999',
            'listing.width' => 'nullable|integer|min:1|max:99999',
            'listing.height' => 'nullable|integer|min:1|max:99999',
        ];
        if ($canCatalog) {
            $rules += [
                'catalog.title' => 'nullable|string|max:255',
                'catalog.description' => 'nullable|string|max:60000',
                'catalog.description_edited' => 'nullable|in:0,1',
                'catalog.photos' => 'nullable|string|max:20000',
                'catalog.price' => 'nullable|numeric|min:0|max:99999999',
                'catalog.weight' => 'nullable|numeric|min:0|max:99999',
                'catalog.length' => 'nullable|numeric|min:0|max:99999',
                'catalog.width' => 'nullable|numeric|min:0|max:99999',
                'catalog.height' => 'nullable|numeric|min:0|max:99999',
            ];
        }
        $data = $request->validate($rules, [], [
            'listing.title' => 'listing title', 'catalog.price' => 'catalog price',
            'listing.weight' => 'listing weight', 'catalog.weight' => 'catalog weight',
        ]);

        if ($canCatalog && isset($data['catalog'])) {
            $c = $data['catalog'];
            $fields = [];
            if (trim((string) ($c['title'] ?? '')) !== '') {
                $fields['title'] = (string) $c['title'];
            }
            if (($c['description_edited'] ?? '0') === '1') {
                $fields['description'] = (string) ($c['description'] ?? '');
            }
            if (array_key_exists('photos', $c) && $c['photos'] !== null) {
                $decoded = json_decode((string) $c['photos'], true);
                if (is_array($decoded)) {
                    $fields['photos'] = $decoded;
                }
            }
            foreach (['price', 'weight', 'length', 'width', 'height'] as $key) {
                if (($c[$key] ?? null) !== null && $c[$key] !== '' && (float) $c[$key] > 0) {
                    $fields[$key] = (float) $c[$key];
                }
            }
            app(CatalogBasicsWriter::class)->write($productId, $fields);
        }

        $l = $data['listing'];
        $listing->{$cols['title']} = ListingContent::own($l['title'] ?? null);
        if (($l['description_edited'] ?? '0') === '1') {
            $listing->{$cols['description']} = ListingContent::descriptionToSave($l['description'] ?? null, $listing->{$cols['description']}, '1');
        }
        if (array_key_exists('photos', $l)) {
            $listing->{$cols['images']} = ListingImages::submitted($productId, $l['photos']);
        }

        $catalog = CatalogCopy::catalog([$productId])[$productId] ?? null;
        if ($catalog !== null) {
            $own = fn ($value, float $catalogValue) => ($value === null || $value === '' || abs((float) $value - $catalogValue) < 0.0005) ? null : (float) $value;
            $pfx = (string) config('catalog.prefix');
            $hasVariations = DB::table($pfx . 'product_option_value')->where('product_id', $productId)->exists();
            if ($hasVariations) {
                $listing->price = null;
            } elseif (array_key_exists('price', $l)) {
                $listing->price = $own($l['price'], $catalog['price']);
            }
            foreach (CatalogCopy::PARCEL as $key => $column) {
                if (array_key_exists($key, $l)) {
                    $value = $own($l[$key], $catalog['parcel'][$key]);
                    $listing->{$column} = ($value !== null && $column !== 'weight') ? (int) round($value) : $value;
                }
            }
        }

        $listing->catalog_seen_at = CatalogCopy::seenAtFor($productId);
        $listing->save();

        return redirect()->to($this->backToListing($productId, $data['back'] ?? null))
            ->with('status', 'Saved.');
    }

    private function backToListing(int $productId, ?string $back): string
    {
        $to = $this->catalogChangeFallback($productId);
        $back = BackTo::safe($back, '');

        return $back === '' ? $to : $to . (str_contains($to, '?') ? '&' : '?') . 'back=' . urlencode($back);
    }

    public function catalogChangeIgnore(Request $request, int $productId)
    {
        $listing = $this->catalogChangeListing($productId);
        CatalogCopy::acknowledge($listing);

        return redirect()->to(BackTo::safe((string) $request->input('back', ''), $this->catalogChangeFallback($productId)))
            ->with('status', 'Catalog change ignored.');
    }
}
