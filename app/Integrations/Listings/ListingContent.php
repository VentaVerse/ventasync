<?php

namespace App\Integrations\Listings;

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
     * @return array{title: string, description: string}
     */
    public static function of(?object $listing, string $coreTitle, string $coreDescription): array
    {
        $own = ($listing !== null && method_exists($listing, 'contentOverrides'))
            ? $listing->contentOverrides()
            : [];

        return [
            'title' => self::pick($own['title'] ?? null, $coreTitle),
            'description' => self::pick($own['description'] ?? null, $coreDescription),
        ];
    }

    /** A blank override is not an override. */
    private static function pick(?string $own, string $core): string
    {
        return ($own !== null && trim($own) !== '') ? $own : $core;
    }
}
