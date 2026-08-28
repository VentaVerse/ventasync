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
        // The two halves of To Ship. Every channel splits the work the same
        // way: the parcel is either still on the bench (To Pack) or on the
        // shelf waiting for the courier (To Handover). They rank inside
        // To Ship so a status placed in either sorts with the work.
        'to_pack' => 'To Pack',
        'to_handover' => 'To Handover',
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
     *
     * The storefront's rows mirror the storefront's OWN queue placement
     * (dumped from its courier-fulfillment status placement table on
     * 2026-08-28). The storefront tells its statuses apart the same way the
     * marketplaces do, so a storefront status lands in the same To Pack or
     * To Handover step a Shopee status would. Board task #167 replaces this
     * list with the placement the storefront reports itself, once its API
     * carries one (#169); until then a status the storefront adds falls
     * into Other, which is visible, rather than being guessed at.
     */
    private const STATUS_STEPS = [
        'venta.orders' => [
            'UNPAID' => 'unpaid',
            'QUOTE_READY_FOR_PAYMENT' => 'unpaid',
            'PENDING' => 'unpaid',
            'FOR_VERIFICATION' => 'unpaid',

            'PROCESSED' => 'to_pack',
            'PAYMENT_RECEIVED' => 'to_pack',
            'ITEM_RESERVED' => 'to_pack',
            'ORDER_PROCESSED' => 'to_pack',
            'ARRIVED_AT_NGD' => 'to_pack',
            'ASSIGNED' => 'to_pack',
            'ACCEPTED' => 'to_pack',
            'ASSIGNING_RIDER' => 'to_pack',
            'PROCESSING' => 'to_pack',

            'ORDER_BEING_SHIPPED' => 'to_handover',
            'START_PICKUP' => 'to_handover',
            'DONE_PICKUP' => 'to_handover',
            'PICKUP_FAILED' => 'to_handover',

            'IN_TRANSIT_TO_MANILA' => 'shipping',
            'DELIVERY_FAILED' => 'shipping',
            'SHIPPED' => 'shipping',
            'IN_TRANSIT' => 'shipping',

            'DELIVERED' => 'delivered',
            'COMPLETE' => 'delivered',
            'COMPLETED' => 'delivered',

            'DENIED' => 'cancelled',
            'CANCELED_REVERSAL' => 'cancelled',
            'FAILED' => 'cancelled',
            'REFUNDED' => 'cancelled',
            'REVERSED' => 'cancelled',
            'CHARGEBACK' => 'cancelled',
            'EXPIRED' => 'cancelled',
            'VOIDED' => 'cancelled',
            'CANCELED' => 'cancelled',
            'CANCELLED' => 'cancelled',

            'RETURNING' => 'failed',
            'RETURNED' => 'failed',
            'LOST' => 'failed',
            'DAMAGED' => 'failed',
        ],
    ];

    /**
     * The steps a surface's statuses can be placed in, in lifecycle order,
     * plus "other" for whatever the surface has no opinion about. This is
     * the row of buckets bucket() fills, and the strip a channel without a
     * fixed status vocabulary renders.
     *
     * @return list<string>
     */
    public static function placements(): array
    {
        return ['unpaid', 'to_pack', 'to_handover', 'shipping', 'delivered', 'cancelled', 'failed', 'other'];
    }

    /**
     * Sort a surface's raw statuses, with their counts, into the steps they
     * belong to.
     *
     *   FulfilmentSteps::bucket('venta.orders', ['Processing' => 3, 'Start Pickup' => 1, 'Odd' => 2])
     *   -> ['unpaid' => ['count' => 0, 'statuses' => []],
     *       'to_pack' => ['count' => 3, 'statuses' => ['Processing']],
     *       'to_handover' => ['count' => 1, 'statuses' => ['Start Pickup']],
     *       ...
     *       'other' => ['count' => 2, 'statuses' => ['Odd']]]
     *
     * Every placement is present, at zero when nothing sits in it, so a
     * caller can render a fixed strip off this without a second lookup. The
     * statuses are returned exactly as the caller spelled them, because
     * they are what the caller will put back into a WHERE IN: the storefront
     * stores its status NAME, and a normalised key would match nothing.
     *
     * One GROUP BY feeds this, so a card counter and the panel strip that
     * both call it over the same rows cannot disagree.
     *
     * @param  iterable<string, int|string>  $statusCounts  raw status => count
     * @return array<string, array{count: int, statuses: list<string>}>
     */
    public static function bucket(string $surface, iterable $statusCounts): array
    {
        $out = [];
        foreach (self::placements() as $step) {
            $out[$step] = ['count' => 0, 'statuses' => []];
        }

        foreach ($statusCounts as $status => $count) {
            $status = (string) $status;
            $step = self::stepFor($surface, $status) ?? 'other';

            // A status placed in a step this row does not have (none today,
            // but the map is the only thing stopping it) is worse lost than
            // visible: file it under Other rather than dropping it.
            if (! isset($out[$step])) {
                $step = 'other';
            }

            $out[$step]['count'] += (int) $count;
            $out[$step]['statuses'][] = $status;
        }

        return $out;
    }

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
