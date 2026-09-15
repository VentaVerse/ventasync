<?php

namespace App\Integrations\Listings;

/**
 * THE TWO TROUBLES A CHANNEL COUNTS, AND THE WORDS FOR THEM.
 *
 * Every channel engine answers the same two questions about its listings -
 * how many need attention, and how many the catalogue has moved under - and
 * until 2026-09-09 each one also wrote its own sentence for the dashboard
 * chip and had nowhere to send anybody.
 *
 * the owner, 2026-09-08, looking at "1 listing needs attention · 133 behind the
 * catalogue" on a card that linked to the orders page: "That doesnt really
 * answer the question needs attention. Im looking at Overview and in
 * listing, but I dont know what Im looking for."
 *
 * So the words are written once, here, and the same words name the filter
 * the figure links to. A number on a dashboard is only a fact if the thing
 * it counted can be reached; the filter behind these buckets is fed by the
 * very query that produced the count (each engine's productIdsIn), so the
 * figure and the rows cannot disagree.
 */
final class ListingTrouble
{
    public const ATTENTION = 'attention';
    public const DRIFT = 'drift';

    /** The buckets a ?state= filter accepts, and nothing else. */
    public const BUCKETS = [self::ATTENTION, self::DRIFT];

    /** The chip's words for one bucket: "2 listings need attention". */
    public static function words(string $bucket, int $count): string
    {
        return $bucket === self::DRIFT
            ? $count . ' catalog change' . ($count === 1 ? '' : 's')
            : $count . ' listing' . ($count === 1 ? ' needs' : 's need') . ' attention';
    }

    /**
     * What the filtered page says it is showing. The same words as the
     * chip, in a sentence, so arriving from the dashboard reads as landing
     * where you were sent rather than somewhere that happens to be narrow.
     */
    public static function showing(string $bucket, int $count): string
    {
        return $bucket === self::DRIFT
            ? 'Showing the ' . $count . ' ' . ($count === 1 ? 'listing' : 'listings') . ' with a catalog change.'
            : 'Showing the ' . $count . ' ' . ($count === 1 ? 'listing that needs' : 'listings that need') . ' attention.';
    }

    /**
     * The dashboard chip's figures: one entry per trouble the channel has,
     * each carrying where it goes. A channel with neither returns nothing
     * and the chip prints no flags line, exactly as before.
     *
     * @param  array{attention: int, drift: int}  $summary  the engine's own counts
     * @param  callable(string): ?string  $urlFor  bucket => the filtered Listings URL
     * @return list<array{text: string, href: ?string, tone: string}>
     */
    public static function figures(array $summary, callable $urlFor): array
    {
        $out = [];
        foreach (self::BUCKETS as $bucket) {
            $count = (int) ($summary[$bucket] ?? 0);
            if ($count < 1) {
                continue;
            }
            $out[] = [
                'text' => self::words($bucket, $count),
                'href' => $urlFor($bucket),
                // Attention is somebody's problem now; drift is a backlog.
                'tone' => $bucket === self::ATTENTION ? 'bad' : 'warn',
            ];
        }

        return $out;
    }

    /** The bucket a request asked for, or null when it asked for nothing valid. */
    public static function asked(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, self::BUCKETS, true) ? $value : null;
    }
}
