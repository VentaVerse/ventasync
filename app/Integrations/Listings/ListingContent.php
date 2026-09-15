<?php

namespace App\Integrations\Listings;

use App\Models\DescriptionTemplate;
use App\Support\Catalog\DescriptionHtml;
use App\Support\Catalog\DescriptionText;

/**
 * What title and description go up for one product on one store.
 *
 * A store listing keeps its own copy of the catalog product's words, made
 * when the listing is made (CatalogCopy). A product with no listing row yet
 * sends the catalog's words, which is what its listing will start with.
 *
 * Every push path calls of(), so no path can pick different words. A
 * channel's listing model says where it keeps them through
 * contentOverrides(); the column names differ per channel (item_name,
 * title, name) and that is the model's business, not the caller's.
 */
class ListingContent
{
    /**
     * @param  ?object  $listing  the store's listing row, or null when it has none yet
     * @param  ?string  $integration  the channel, when the store's description
     *                  templates should wrap the result
     * @param  ?int  $storeId  that channel's store
     * @return array{title: string, description: string}
     */
    public static function of(?object $listing, string $coreTitle, string $coreDescription, ?string $integration = null, ?int $storeId = null): array
    {
        if ($listing === null || ! method_exists($listing, 'contentOverrides') || (property_exists($listing, 'exists') && ! $listing->exists)) {
            /* A catalog description can be stored entity-encoded, the older
             * way; decoded here it reaches every store as markup. */
            $decoded = DescriptionHtml::decoded($coreDescription);
            $title = CatalogCopy::title($coreTitle);
            $description = DescriptionText::looksLikeHtml($decoded) ? DescriptionHtml::forEditor($decoded) : $decoded;
        } else {
            $own = $listing->contentOverrides();
            $title = trim((string) ($own['title'] ?? ''));
            $description = (string) ($own['description'] ?? '');
        }

        /* The store's own words above and below: a returns policy or a
         * warranty note written once and carried by every listing that
         * points at it. */
        if ($integration !== null && $storeId !== null && $listing !== null) {
            $description = self::wrap(
                $description,
                DescriptionTemplate::bodyOf(self::intOrNull($listing->description_prefix_id ?? null), $integration, $storeId),
                DescriptionTemplate::bodyOf(self::intOrNull($listing->description_suffix_id ?? null), $integration, $storeId)
            );
        }

        return ['title' => $title, 'description' => $description];
    }

    /**
     * The name each row of a Listings page or group page shows: the store's
     * own title when its listing has one, the catalog's name otherwise.
     *
     * @param  iterable<object>  $rows  the page's rows, each with product_id and name
     * @param  iterable<object>  $listings  this store's listing rows for those products
     * @return array<int, string>  product id => the name to show
     */
    public static function rowTitles(iterable $rows, iterable $listings): array
    {
        $own = [];
        foreach ($listings as $listing) {
            if (! method_exists($listing, 'contentOverrides')) {
                continue;
            }
            $title = trim((string) ($listing->contentOverrides()['title'] ?? ''));
            if ($title !== '') {
                $own[(int) $listing->product_id] = $title;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            $out[$pid] = $own[$pid] ?? CatalogCopy::title((string) ($row->name ?? ''));
        }

        return $out;
    }

    /**
     * The store's templates around a description that has already been
     * resolved elsewhere. Every channel's payload builder calls this, so a
     * push from any door carries the store's policy text.
     */
    public static function wrapDescription(string $description, string $integration, int $storeId, ?object $listing): string
    {
        if ($listing === null || $storeId <= 0) {
            return $description;
        }

        return self::wrap(
            $description,
            DescriptionTemplate::bodyOf(self::intOrNull($listing->description_prefix_id ?? null), $integration, $storeId),
            DescriptionTemplate::bodyOf(self::intOrNull($listing->description_suffix_id ?? null), $integration, $storeId)
        );
    }

    /** Prefix, then the description, then suffix; blanks simply do not appear. */
    private static function wrap(string $description, string $prefix, string $suffix): string
    {
        return implode("\n\n", array_filter([
            $prefix,
            trim($description),
            $suffix,
        ], fn ($part) => trim((string) $part) !== ''));
    }

    private static function intOrNull($value): ?int
    {
        return ($value === null || (int) $value <= 0) ? null : (int) $value;
    }

    /** A title as typed, to store; blank is null. */
    public static function own(?string $submitted): ?string
    {
        $submitted = trim((string) $submitted);

        return $submitted === '' ? null : $submitted;
    }

    /**
     * The description to store for a listing.
     *
     * When $edited is "0" the editor was loaded but not changed, so the
     * current value is kept: rich editors normalise markup, which would
     * otherwise look like an edit. Stored HTML is sanitised like catalog
     * descriptions, and an empty editor counts as blank.
     *
     * $plainText is for a channel whose box holds words only. Words equal to
     * the current description's are no edit, so a description kept as markup
     * (with its pictures) stays as it is.
     */
    public static function descriptionToSave(?string $submitted, ?string $current, ?string $edited, bool $plainText = false): ?string
    {
        if ($edited === '0') {
            return $current;
        }

        if ($plainText) {
            $text = DescriptionText::of($submitted);
            if ($current !== null && $text === DescriptionText::of($current)) {
                return $current;
            }

            return $text === '' ? null : $text;
        }

        if (DescriptionText::isBlank($submitted)) {
            return null;
        }
        $clean = trim(DescriptionHtml::store($submitted));

        return $clean === '' ? null : $clean;
    }

    /** What to SHOW in the box: the listing's own words, else the catalog's for a listing not made yet. */
    public static function editable(?string $own, string $core): string
    {
        return ($own !== null && trim($own) !== '') ? $own : $core;
    }
}
