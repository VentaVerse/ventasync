<?php

namespace App\Integrations\Listings;

use App\Support\Catalog\DescriptionHtml;
use App\Support\Catalog\DescriptionText;
use App\Support\Catalog\ProductImages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A store listing keeps its own copy of the catalog product. The copy is
 * made when the listing is made, and a later catalog edit never changes it;
 * a Catalog change is how the store finds out the two came apart.
 *
 * Title, description and photos are copied. Price and parcel are not: a
 * blank listing price or parcel is the catalog's, so a catalog price change
 * still reaches the price push. Both are compared all the same, because a
 * listing can hold its own.
 *
 * A listing counts as compared (catalog_seen_at) when it is made, and when
 * its comparison panel is saved or ignored. It shows a Catalog change when
 * the catalog product was modified after that and the two differ.
 *
 * Channel-agnostic: a caller passes the columns its listing keeps the copy
 * in, as ['title' => ..., 'description' => ..., 'images' => ..., 'plain' => bool].
 * 'plain' is for a channel whose listing description can hold plain words:
 * the same words as the catalog's then count as the same description.
 */
final class CatalogCopy
{
    public const FIELDS = ['title', 'description', 'photos', 'price', 'parcel'];

    /** The listing's parcel columns, against the catalog's. */
    public const PARCEL = ['weight' => 'weight', 'length' => 'package_length', 'width' => 'package_width', 'height' => 'package_height'];

    /**
     * The catalog side of the comparison.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{title: string, description: string, photos: list<string>, price: float, parcel: array<string, float>, modified: ?string}>
     */
    public static function catalog(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($ids === []) {
            return [];
        }

        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');
        $rows = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($lang) {
                $j->on('pd.product_id', '=', 'p.product_id')->where('pd.language_id', '=', $lang);
            })
            ->whereIn('p.product_id', $ids)
            ->get(['p.product_id', 'p.price', 'p.weight', 'p.length', 'p.width', 'p.height', 'p.date_modified', 'pd.name', 'pd.description']);

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            $out[$pid] = [
                'title' => self::title((string) ($row->name ?? '')),
                'description' => DescriptionHtml::forEditor((string) ($row->description ?? '')),
                'photos' => ProductImages::paths($pid),
                'price' => (float) ($row->price ?? 0),
                'parcel' => [
                    'weight' => (float) ($row->weight ?? 0),
                    'length' => (float) ($row->length ?? 0),
                    'width' => (float) ($row->width ?? 0),
                    'height' => (float) ($row->height ?? 0),
                ],
                'modified' => $row->date_modified !== null ? (string) $row->date_modified : null,
            ];
        }

        return $out;
    }

    /**
     * The listing side, in the catalog's shape: its own title, description
     * and photos, and its price and parcel as they go up.
     *
     * @param  object  $listing  a listing row or model, with product_id and the copy columns
     * @param  array<string, mixed>  $cols
     * @param  array<string, mixed>  $catalog  this product's entry from catalog()
     * @return array{title: string, description: string, photos: list<string>, price: float, parcel: array<string, float>}
     */
    public static function listing(object $listing, array $cols, array $catalog): array
    {
        $images = $listing->{$cols['images']} ?? null;
        if (is_string($images)) {
            $images = json_decode($images, true);
        }

        $number = fn ($value) => ($value === null || $value === '') ? null : (float) $value;
        $parcel = [];
        foreach (self::PARCEL as $key => $column) {
            $parcel[$key] = $number($listing->{$column} ?? null) ?? (float) $catalog['parcel'][$key];
        }

        return [
            'title' => self::title((string) ($listing->{$cols['title']} ?? '')),
            'description' => (string) ($listing->{$cols['description']} ?? ''),
            'photos' => ProductImages::paths((int) $listing->product_id, is_array($images) ? $images : null),
            'price' => $number($listing->price ?? null) ?? (float) $catalog['price'],
            'parcel' => $parcel,
        ];
    }

    /**
     * The fields where the listing and the catalog differ, ignoring what
     * nobody can see: spacing, line breaks, how the text was stored.
     *
     * @return list<string>
     */
    public static function differences(array $listing, array $catalog, bool $plain = false): array
    {
        $out = [];
        if ($listing['title'] !== $catalog['title']) {
            $out[] = 'title';
        }
        if (! self::sameDescription($listing['description'], $catalog['description'], $plain)) {
            $out[] = 'description';
        }
        if ($listing['photos'] !== $catalog['photos']) {
            $out[] = 'photos';
        }
        if (abs($listing['price'] - $catalog['price']) > 0.00001) {
            $out[] = 'price';
        }
        foreach ($catalog['parcel'] as $key => $value) {
            if (abs(($listing['parcel'][$key] ?? 0) - $value) > 0.0005) {
                $out[] = 'parcel';
                break;
            }
        }

        return $out;
    }

    /**
     * Fills a new listing's blank copy columns from the catalog and marks it
     * compared. Columns already given a value are left as they are.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $listing
     * @param  array<string, mixed>  $cols
     */
    public static function fill(object $listing, array $cols): void
    {
        $pid = (int) ($listing->product_id ?? 0);
        $catalog = $pid > 0 ? (self::catalog([$pid])[$pid] ?? null) : null;
        if ($catalog === null) {
            return;
        }

        if (trim((string) ($listing->{$cols['title']} ?? '')) === '') {
            $listing->{$cols['title']} = $catalog['title'];
        }
        if (DescriptionText::isBlank($listing->{$cols['description']} ?? null)) {
            $listing->{$cols['description']} = $catalog['description'];
        }
        if (empty($listing->{$cols['images']}) && $catalog['photos'] !== []) {
            $listing->{$cols['images']} = method_exists($listing, 'hasCast') && ! $listing->hasCast($cols['images'])
                ? json_encode($catalog['photos'])
                : $catalog['photos'];
        }
        $listing->catalog_seen_at = now();
    }

    /**
     * Which listings show a Catalog change, with the fields that differ.
     * A listing whose catalog product was modified since it was compared,
     * but which turns out the same, is marked compared now and is not
     * checked again until the catalog moves.
     *
     * @param  iterable<object>  $listings  rows of one listing table
     * @param  array<string, mixed>  $cols
     * @return array<int, list<string>>  product id => the fields that differ
     */
    public static function pending(iterable $listings, string $table, array $cols): array
    {
        $rows = [];
        foreach ($listings as $listing) {
            if ($listing !== null && (int) ($listing->product_id ?? 0) > 0) {
                $rows[] = $listing;
            }
        }
        if ($rows === []) {
            return [];
        }

        $pfx = (string) config('catalog.prefix');
        $modified = DB::table($pfx . 'product')
            ->whereIn('product_id', array_map(fn ($r) => (int) $r->product_id, $rows))
            ->pluck('date_modified', 'product_id');

        $candidates = array_values(array_filter($rows, function ($row) use ($modified) {
            $seen = $row->catalog_seen_at ?? null;
            if ($seen === null) {
                return true;
            }
            $moved = $modified[(int) $row->product_id] ?? null;

            return $moved !== null && Carbon::parse((string) $moved)->gt(Carbon::parse((string) $seen));
        }));
        if ($candidates === []) {
            return [];
        }

        $catalog = self::catalog(array_map(fn ($r) => (int) $r->product_id, $candidates));
        $out = [];
        foreach ($candidates as $row) {
            $entry = $catalog[(int) $row->product_id] ?? null;
            if ($entry === null) {
                continue;
            }
            $diff = self::differences(self::listing($row, $cols, $entry), $entry, (bool) ($cols['plain'] ?? false));
            if ($diff === []) {
                $seen = self::seenAt($entry['modified']);
                DB::table($table)->where('id', (int) $row->id)->update(['catalog_seen_at' => $seen]);
                if (is_object($row) && method_exists($row, 'setRawAttributes')) {
                    $row->catalog_seen_at = $seen;
                }
                continue;
            }
            $out[(int) $row->product_id] = $diff;
        }

        return $out;
    }

    /** Marks a listing compared with the catalog as it stands now. */
    public static function acknowledge(object $listing): void
    {
        $listing->forceFill(['catalog_seen_at' => self::seenAtFor((int) $listing->product_id)])->save();
    }

    /**
     * The moment a listing is compared as of: now, or the catalog product's
     * own last change when that reads later, so the comparison is never
     * older than the change it answered.
     */
    public static function seenAtFor(int $productId): Carbon
    {
        $modified = DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $productId)->value('date_modified');

        return self::seenAt($modified !== null ? (string) $modified : null);
    }

    private static function seenAt(?string $modified): Carbon
    {
        $now = now();
        if ($modified === null) {
            return $now;
        }
        $moved = Carbon::parse($modified);

        return $moved->gt($now) ? $moved : $now;
    }

    /** A title as it reads: entities decoded, outer spaces gone. */
    public static function title(string $title): string
    {
        return trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function sameDescription(string $listing, string $catalog, bool $plain): bool
    {
        $shape = fn (string $html) => trim((string) preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], DescriptionHtml::forEditor($html)));
        if ($shape($listing) === $shape($catalog)) {
            return true;
        }

        return $plain
            && ! DescriptionText::looksLikeHtml($listing)
            && DescriptionText::of($listing) === DescriptionText::of($catalog);
    }
}
