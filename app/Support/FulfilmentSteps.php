<?php

namespace App\Support;

/**
 * One vocabulary for the fulfilment lifecycle, across every channel.
 *
 * Four panels are presented as one surface. A packer works Shopee, then
 * Lazada, then TikTok, then the storefront, and the whole value of putting
 * them on one surface is that the mental model transfers. It did not:
 *
 *   Shopee    All / Unpaid / To Ship / Shipping / Delivered / Cancelled /
 *             Failed Delivery / Other
 *   Lazada    ... / CANCELLATION -> "Cancellation"
 *   TikTok    ... / Completed / Cancelled, and no Failed Delivery
 *   Venta     All / Delivered / Cancelled / Ready for pickup / Shipped /
 *             In transit
 *
 * Lazada said "Cancellation" where Shopee said "Cancelled", for the same
 * step, three centimetres apart in a shift. And the storefront's strip came
 * out of a GROUP BY in count order, so its one actionable step sat fourth,
 * behind two terminal states, where every other channel puts the work third.
 *
 * Each channel keeps its own KEYS - those are marketplace filter values and
 * this class has no business renaming them. What it owns is the two things
 * that were drifting: the words a human reads, and the order they appear in.
 *
 * This sits beside ChannelStatusTone deliberately. That class already
 * absorbed the per-status tone and label maps for exactly this reason; this
 * is the same job one level up, at the step rather than the status.
 */
final class FulfilmentSteps
{
    /**
     * The lifecycle, in order, with the word a human reads for each.
     *
     * Order is the point as much as the wording. Anything built through
     * this class comes out in this sequence regardless of how the caller
     * declared it or what order a database handed it back in.
     *
     * "Other" is last because it is defined by exclusion: it holds whatever
     * a marketplace sent that none of the steps above claim. A bucket named
     * for what it is not cannot be positioned by what it contains.
     */
    private const STEPS = [
        'all' => 'All',
        'unpaid' => 'Unpaid',
        'to_ship' => 'To Ship',
        'ready' => 'Ready for pickup',
        'shipping' => 'Shipping',
        'in_transit' => 'In transit',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'failed' => 'Failed Delivery',
        'other' => 'Other',
    ];

    /**
     * Where a channel's own statuses sit in the lifecycle.
     *
     * Only for surfaces whose strip is built from the statuses actually
     * present rather than from a fixed set of steps. The storefront's
     * statuses are OpenCart order-status names, which a store can add to, so
     * this is deliberately partial: a status listed here is positioned, and
     * anything else keeps its place at the end of the strip rather than
     * being guessed at. Being last is a worse outcome than being third; being
     * silently dropped, or filed under a step it does not belong to, is a
     * worse outcome than both.
     *
     * Keyed the same way ChannelStatusTone keys its maps - uppercased, with
     * spaces folded to underscores - so "Start Pickup" and "START_PICKUP"
     * are one entry.
     */
    private const STATUS_STEPS = [
        'venta.orders' => [
            'PENDING' => 'unpaid',
            'FOR_VERIFICATION' => 'unpaid',
            'PROCESSING' => 'to_ship',
            'START_PICKUP' => 'ready',
            'SHIPPED' => 'shipping',
            'IN_TRANSIT_TO_MANILA' => 'in_transit',
            'DELIVERED' => 'delivered',
            'COMPLETE' => 'completed',
            'COMPLETED' => 'completed',
            'CANCELED' => 'cancelled',
            'CANCELLED' => 'cancelled',
            'FAILED' => 'failed',
        ],
    ];

    /**
     * The step a raw status belongs to on a surface, or null when the
     * surface has no opinion about it.
     */
    public static function stepFor(string $surface, ?string $status): ?string
    {
        // Trim BEFORE folding spaces. The other way round, a padded
        // " Start Pickup " becomes "__START_PICKUP_" and matches nothing,
        // because trim() does not consider an underscore whitespace.
        $key = strtoupper(str_replace(' ', '_', trim((string) $status)));

        return self::STATUS_STEPS[$surface][$key] ?? null;
    }

    /**
     * The word for a step. An unknown step is returned as given rather than
     * blanked: a channel adding a step it has not registered here should
     * look wrong in review, not disappear from the strip in production.
     */
    public static function label(string $step): string
    {
        return self::STEPS[$step] ?? $step;
    }

    public static function knows(string $step): bool
    {
        return isset(self::STEPS[$step]);
    }

    /**
     * Where a step sits in the lifecycle. Unknown steps sort after every
     * known one, before "other", in the order they were declared.
     */
    public static function rank(string $step): int
    {
        $rank = array_search($step, array_keys(self::STEPS), true);

        return $rank === false ? count(self::STEPS) - 1 : $rank;
    }

    /**
     * Build a channel's tab strip from a map of ITS keys to shared steps.
     *
     *   FulfilmentSteps::tabs(['ALL' => 'all', 'PENDING' => 'to_ship', ...])
     *   -> ['ALL' => 'All', 'PENDING' => 'To Ship', ...] in lifecycle order
     *
     * The keys are the channel's own filter values and pass through
     * untouched; only the labels and the ordering come from here.
     *
     * @param  array<string, string>  $keyToStep
     * @return array<string, string>
     */
    public static function tabs(array $keyToStep): array
    {
        uasort($keyToStep, fn ($a, $b) => self::rank($a) <=> self::rank($b));

        return array_map(fn ($step) => self::label($step), $keyToStep);
    }

    /**
     * Reorder a channel's own status list into lifecycle order.
     *
     * For the storefront, whose statuses are its own vocabulary rather than
     * a fixed set of steps: "Ready for pickup" is a real distinct thing and
     * is not renamed to "To Ship". It is only moved to where the work sits
     * on every other channel.
     *
     * Statuses with no mapping keep their relative order and follow the
     * mapped ones, so an unrecognised status is never silently dropped.
     *
     * @param  iterable<string, mixed>  $statuses  keyed by raw status
     * @return array<string, mixed>
     */
    public static function order(iterable $statuses, string $surface): array
    {
        $out = [];

        foreach ($statuses as $key => $value) {
            $out[(string) $key] = $value;
        }

        $seen = array_keys($out);

        uksort($out, function ($a, $b) use ($surface, $seen) {
            $stepA = self::stepFor($surface, $a);
            $stepB = self::stepFor($surface, $b);
            $rankA = $stepA === null ? PHP_INT_MAX : self::rank($stepA);
            $rankB = $stepB === null ? PHP_INT_MAX : self::rank($stepB);

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            // Stable: equal ranks keep the order they arrived in, so two
            // statuses sharing a step do not swap places between requests.
            return array_search($a, $seen, true) <=> array_search($b, $seen, true);
        });

        return $out;
    }
}
