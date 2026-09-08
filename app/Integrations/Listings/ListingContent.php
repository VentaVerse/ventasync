<?php

namespace App\Integrations\Listings;

use App\Models\DescriptionTemplate;

/**
 * What title and description go up for one product on one store.
 *
 * THE RULE, one place (the owner, 2026-09-07): the store listing's own words
 * win; where it has none, the core product's words go instead. An empty
 * column is not missing data, it means "follow the catalog", which is what
 * the greyed placeholder on the Content card shows.
 *
 * It exists because the rule used to be re-implemented at every push path,
 * about four per channel, and one of them always forgot. A push from a
 * group page sent the CORE product's title while the store listing's own
 * title sat saved and unused, on four of the five channels. Now there is
 * one answer and nothing else to call.
 *
 * A channel's listing model says what it holds through contentOverrides();
 * the column names differ per channel (item_name, title, name) and that is
 * the model's business, not the caller's.
 */
class ListingContent
{
    /**
     * @param  ?object  $listing  the store's listing row, or null when it has none yet
     * @param  ?string  $integration  the channel, when the store's description
     *                  templates should wrap the result (2026-09-07)
     * @param  ?int  $storeId  that channel's store
     * @return array{title: string, description: string}
     */
    public static function of(?object $listing, string $coreTitle, string $coreDescription, ?string $integration = null, ?int $storeId = null): array
    {
        $own = ($listing !== null && method_exists($listing, 'contentOverrides'))
            ? $listing->contentOverrides()
            : [];

        $description = self::pick($own['description'] ?? null, $coreDescription);

        /* The store's own words above and below (the owner, 2026-09-07): a
         * returns policy or a warranty note written once and carried by
         * every listing that points at it. It wraps whichever description
         * won above, so a listing with its own wording keeps the policy too. */
        if ($integration !== null && $storeId !== null && $listing !== null) {
            $description = self::wrap(
                $description,
                DescriptionTemplate::bodyOf(self::intOrNull($listing->description_prefix_id ?? null), $integration, $storeId),
                DescriptionTemplate::bodyOf(self::intOrNull($listing->description_suffix_id ?? null), $integration, $storeId)
            );
        }

        return [
            'title' => self::pick($own['title'] ?? null, $coreTitle),
            'description' => $description,
        ];
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

    /**
     * What to SAVE for a field the operator edited (the owner, 2026-09-07).
     *
     * The box is pre-filled with the catalog's words so a few of them can be
     * changed without retyping the rest. That must not turn every listing
     * into a frozen copy: text that still matches the catalog is stored as
     * null, which is what "follow the catalog" is. Only a real edit is kept.
     */
    public static function overrideOrNull(?string $submitted, string $core): ?string
    {
        $submitted = trim((string) $submitted);
        if ($submitted === '' || $submitted === trim($core)) {
            return null;
        }

        return $submitted;
    }

    /** What to SHOW in the box: the listing's own words, else the catalog's. */
    public static function editable(?string $own, string $core): string
    {
        return ($own !== null && trim($own) !== '') ? $own : $core;
    }

    /** A blank override is not an override. */
    private static function pick(?string $own, string $core): string
    {
        return ($own !== null && trim($own) !== '') ? $own : $core;
    }
}
