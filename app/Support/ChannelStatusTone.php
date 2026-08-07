<?php

namespace App\Support;

/**
 * Per-channel status tone and label maps for the marketplace order surfaces.
 *
 * Every channel names its statuses differently (Shopee shouts in uppercase,
 * Lazada whispers in lowercase, TikTok has its own set again) and every one
 * of those raw strings is unfit to show a packer. Two decisions have to be
 * made per status, on every marketplace surface: what colour it carries, and
 * what words a human reads. Both used to live in a @php block at the top of
 * whichever view needed them, which was fine while Shopee's orders page was
 * the only surface and started drifting the moment the fulfilment hub became
 * its second caller. Lazada's conversion would have created the third and
 * fourth copies, so the maps come here instead and later channels add
 * entries rather than files.
 *
 * TONE PHILOSOPHY, one rule, applied per channel:
 *
 *   success  the operator's move, right now. This is the only tone that
 *            resolves to the accent colour, so it is the one thing the eye
 *            lands on when a hundred rows are on screen. Spend it nowhere
 *            else.
 *   warning  waiting on someone the seller can chase (an unpaid buyer, a
 *            parcel coming back).
 *   info     waiting on someone the seller cannot chase (a courier in
 *            transit, a marketplace running its own review).
 *   neutral  settled. A finished order should recede, which is why
 *            COMPLETED / delivered are neutral and not the success tone:
 *            x-badge--success and the accent are the same token, and a page
 *            where "pack this now" and "done weeks ago" are the same colour
 *            has no signal left.
 *   danger   ended badly. Cancellations, failed deliveries, losses.
 *
 * LABELS say the plain thing. No marketplace constant reaches a screen raw,
 * and no invented vocabulary replaces it: "To pack", not "PENDING", and not
 * "Fulfilment stage 1" either.
 */
class ChannelStatusTone
{
    /**
     * Tones keyed by surface ("{channel}.{page}") then by normalised status.
     *
     * @var array<string, array<string, string>>
     */
    private const TONES = [
        // Shopee orders. Moved verbatim from
        // extensions/shopee/views/orders/_panel.blade.php.
        'shopee.orders' => [
            'UNPAID'             => 'warning',
            'READY_TO_SHIP'      => 'success',
            'PROCESSED'          => 'info',
            'SHIPPED'            => 'info',
            'TO_CONFIRM_RECEIVE' => 'info',
            'COMPLETED'          => 'neutral',
            'CANCELLED'          => 'danger',
            'IN_CANCEL'          => 'danger',
            'RETRY_SHIP'         => 'warning',
        ],

        // Shopee returns. Moved verbatim from
        // extensions/shopee/views/orders/returns.blade.php. No status here
        // takes the success tone: the page has no mutating row action, so
        // there is nothing on it that is the operator's move.
        'shopee.returns' => [
            'REQUESTED'           => 'warning',
            'PROCESSING'          => 'warning',
            'ACCEPTED'            => 'neutral',
            'JUDGING'             => 'info',
            'SELLER_DISPUTE'      => 'info',
            'REFUND_PAID'         => 'neutral',
            'SELLER_COMPENSATION' => 'neutral',
            'CLOSED'              => 'danger',
            'CANCELLED'           => 'danger',
        ],

        // Lazada orders. Statuses arrive lowercase and are grouped into tabs
        // by LazadaOrderController::index()'s $tabStatusMap; the keys below
        // are that map's full status list, normalised.
        //
        // Lazada's fulfilment has two seller steps where Shopee has one:
        // pack the parcel, then arrange the shipment. Both are genuinely
        // "your move", so all three of pending / repacked / packed take the
        // success tone. Picking one of the two steps to be the accent one
        // and demoting the other would be arbitrary, and the sub-tabs
        // already separate them for anyone working a single step.
        'lazada.orders' => [
            'UNPAID'               => 'warning',
            'PENDING'              => 'success',
            'REPACKED'             => 'success',
            'PACKED'               => 'success',
            'READY_TO_SHIP'        => 'info',
            'SHIPPED'              => 'info',
            'DELIVERED'            => 'neutral',
            'CONFIRMED'            => 'neutral',
            'CANCELED'             => 'danger',
            'CANCELLED'            => 'danger',
            'FAILED_DELIVERY'      => 'danger',
            'LOST_BY_3PL'          => 'danger',
            'DAMAGED_BY_3PL'       => 'danger',
            'SHIPPED_BACK'         => 'warning',
            'SHIPPED_BACK_SUCCESS' => 'neutral',
        ],

        // TikTok orders. Statuses arrive UPPERCASE and the keys below are
        // exactly the seven strings TikTokOrdersPanel's own $tabStatusMap
        // groups into tabs, plus nothing invented: anything TikTok adds later
        // falls through to neutral and prints itself legibly.
        //
        // One status takes the accent, AWAITING_SHIPMENT, because it is the
        // only one with a marketplace write behind it (Ship). Once an order
        // is AWAITING_COLLECTION the seller has done their part and is
        // waiting on a courier to arrive, which is the same kind of waiting
        // as IN_TRANSIT and takes the same tone. That is a narrower spend
        // than Lazada's three success statuses, and correctly so: Lazada
        // genuinely has two seller steps before handover, TikTok has one.
        'tiktok.orders' => [
            'UNPAID'               => 'warning',
            'AWAITING_SHIPMENT'    => 'success',
            'AWAITING_COLLECTION'  => 'info',
            'IN_TRANSIT'           => 'info',
            'DELIVERED'            => 'neutral',
            'COMPLETED'            => 'neutral',
            'CANCELLED'            => 'danger',
        ],

        // Lazada returns. Keys cover every string
        // LazadaOrderController::returns()'s own $tabStatusMap matches on,
        // including the spaced spellings Lazada sends ("return initiated"),
        // which normalise to the underscored key. Same rule as Shopee's
        // returns: no success tone, because the page has no row action that
        // changes anything.
        'lazada.returns' => [
            'RETURN_INITIATED'   => 'warning',
            'REQUESTED'          => 'warning',
            'PENDING'            => 'warning',
            'IN_PROGRESS'        => 'warning',
            'RETURN_IN_PROGRESS' => 'warning',
            'PROCESSING'         => 'warning',
            'RECEIVED'           => 'warning',
            'APPROVED'           => 'info',
            'SHIPPED_BACK'       => 'info',
            'DISPUTE'            => 'info',
            'DISPUTE_IN_PROGRESS' => 'info',
            'REFUND_ISSUED'      => 'neutral',
            'REFUND_PAID'        => 'neutral',
            'REFUNDED'           => 'neutral',
            'CLOSED'             => 'neutral',
            'COMPLETED'          => 'neutral',
            'REJECTED'           => 'danger',
            'CANCELLED'          => 'danger',
            'CANCELED'           => 'danger',
        ],
    ];

    /**
     * Labels keyed the same way.
     *
     * @var array<string, array<string, string>>
     */
    private const LABELS = [
        'shopee.orders' => [
            'UNPAID'             => 'Unpaid',
            'READY_TO_SHIP'      => 'Ready to ship',
            'PROCESSED'          => 'To handover',
            'SHIPPED'            => 'Shipped',
            'TO_CONFIRM_RECEIVE' => 'Awaiting confirmation',
            'COMPLETED'          => 'Completed',
            'CANCELLED'          => 'Cancelled',
            'IN_CANCEL'          => 'Cancelling',
            'RETRY_SHIP'         => 'Delivery failed',
        ],

        'shopee.returns' => [
            'REQUESTED'           => 'Requested',
            'PROCESSING'          => 'Processing',
            'ACCEPTED'            => 'Accepted',
            'JUDGING'             => 'Under review',
            'SELLER_DISPUTE'      => 'Disputed',
            'REFUND_PAID'         => 'Refunded',
            'SELLER_COMPENSATION' => 'Compensated',
            'CLOSED'              => 'Closed',
            'CANCELLED'           => 'Cancelled',
        ],

        // Each label names the step in the words the packing desk already
        // uses, which are the words on Lazada's own sub-tabs: To Pack, To
        // Arrange Shipment, To Handover. "3PL" is Lazada's term for the
        // carrier and means nothing to a packer, so it becomes "courier".
        'lazada.orders' => [
            'UNPAID'               => 'Unpaid',
            'PENDING'              => 'To pack',
            'REPACKED'             => 'To pack again',
            'PACKED'               => 'To arrange shipment',
            'READY_TO_SHIP'        => 'To hand over',
            'SHIPPED'              => 'Shipped',
            'DELIVERED'            => 'Delivered',
            'CONFIRMED'            => 'Received by buyer',
            'CANCELED'             => 'Cancelled',
            'CANCELLED'            => 'Cancelled',
            'FAILED_DELIVERY'      => 'Delivery failed',
            'LOST_BY_3PL'          => 'Lost by courier',
            'DAMAGED_BY_3PL'       => 'Damaged by courier',
            'SHIPPED_BACK'         => 'On its way back',
            'SHIPPED_BACK_SUCCESS' => 'Returned to you',
        ],

        // TikTok's own constants are the marketplace's internal vocabulary
        // ("AWAITING_COLLECTION" is a courier collecting a parcel, not a
        // buyer collecting an order), so each one is said in the words the
        // packing desk uses, which are the words on this channel's own
        // sub-tabs: To Pack, To Handover. "Shipped" rather than "In transit"
        // so the same physical state reads identically on all three
        // channels; Shopee's SHIPPED and Lazada's SHIPPED already say it.
        'tiktok.orders' => [
            'UNPAID'               => 'Unpaid',
            'AWAITING_SHIPMENT'    => 'To pack',
            'AWAITING_COLLECTION'  => 'To hand over',
            'IN_TRANSIT'           => 'Shipped',
            'DELIVERED'            => 'Delivered',
            'COMPLETED'            => 'Completed',
            'CANCELLED'            => 'Cancelled',
        ],

        'lazada.returns' => [
            'RETURN_INITIATED'    => 'Return started',
            'REQUESTED'           => 'Requested',
            'PENDING'             => 'Awaiting review',
            'IN_PROGRESS'         => 'In progress',
            'RETURN_IN_PROGRESS'  => 'In progress',
            'PROCESSING'          => 'Processing',
            'RECEIVED'            => 'Item received',
            'APPROVED'            => 'Approved',
            'SHIPPED_BACK'        => 'On its way back',
            'DISPUTE'             => 'Disputed',
            'DISPUTE_IN_PROGRESS' => 'Disputed',
            'REFUND_ISSUED'       => 'Refunded',
            'REFUND_PAID'         => 'Refunded',
            'REFUNDED'            => 'Refunded',
            'CLOSED'              => 'Closed',
            'COMPLETED'           => 'Completed',
            'REJECTED'            => 'Rejected',
            'CANCELLED'           => 'Cancelled',
            'CANCELED'            => 'Cancelled',
        ],
    ];

    /**
     * Normalised lookup key for a raw marketplace status.
     *
     * Uppercased and trimmed so Lazada's lowercase and Shopee's uppercase
     * constants meet in one place, with spaces folded to underscores so
     * Lazada's two spellings of the same reverse status ("return initiated"
     * and "return_initiated", both of which its own controller matches on)
     * resolve to a single entry.
     */
    private static function key(?string $status): string
    {
        return strtoupper(trim(str_replace(' ', '_', (string) $status)));
    }

    /**
     * Badge tone for a status on a given surface, or 'neutral' for anything
     * unmapped. An unrecognised status should read as unremarkable, not
     * accidentally alarming or reassuring.
     */
    public static function toneFor(string $surface, ?string $status): string
    {
        return self::TONES[$surface][self::key($status)] ?? 'neutral';
    }

    /**
     * Human label for a status on a given surface.
     *
     * Unmapped statuses fall back to the raw constant made readable
     * (underscores to spaces, sentence case) rather than being hidden: a new
     * status the marketplace invents next quarter should show up as itself,
     * legibly, instead of silently reading as "Unknown". An empty status is
     * the one case that genuinely has nothing to say.
     */
    public static function labelFor(string $surface, ?string $status): string
    {
        $raw = (string) $status;
        $key = self::key($raw);

        if (isset(self::LABELS[$surface][$key])) {
            return self::LABELS[$surface][$key];
        }

        return $raw !== ''
            ? ucfirst(strtolower(str_replace('_', ' ', $key)))
            : 'Unknown';
    }
}
