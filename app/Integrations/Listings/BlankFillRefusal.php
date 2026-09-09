<?php

namespace App\Integrations\Listings;

/**
 * A BLANK-STATUS FILL THE STORE REFUSED IS REPORTED, NEVER SWALLOWED.
 *
 * Every Listings page fills its own blanks: a row linked by a push has no
 * status until somebody asks, so the page asks once, for those rows only,
 * up to ten a view. Every channel stopped at the first non-answer, which
 * is right - a store that is down leaves the rest blank rather than
 * guessed - and every channel then said nothing about it, which is not.
 *
 * From the operator's chair a swallowed refusal looks exactly like the
 * thing the owner reported on 2026-09-09: rows that were fine yesterday now
 * read "Not checked yet" and nothing anywhere says why. An expired token
 * looks like this. So does a store behind a firewall, and a storefront
 * mid-deploy.
 *
 * The sentence is written once so no two channels phrase the same silence
 * differently, and it goes in the page's own flash rather than in the
 * "this store cannot be asked at all" note: the counts on the strip are
 * real when a fill fails, so the tabs stay up.
 */
final class BlankFillRefusal
{
    /**
     * @param  string  $channel  the channel or store's name, as the operator reads it
     * @param  ?string  $reason  what the store said, when it said anything
     */
    public static function sentence(string $channel, ?string $reason = null): string
    {
        $reason = trim((string) $reason);
        $said = $reason !== '' ? ' ' . rtrim($reason, '.') . '.' : '';

        return $channel . ' did not answer for the listings whose status is not shown yet.' . $said
            . ' Press Refresh, or wait for the scheduled refresh.';
    }
}
