<?php

namespace App\Support;

/**
 * Per-surface status tone and label maps.
 *
 * Started as the marketplace order surfaces and now also carries purchasing,
 * whose purchase-order lifecycle is its own vocabulary with the same two
 * decisions to make per status. The class name still says "Channel" because
 * renaming it would touch every marketplace view for no behaviour change; read
 * it as "surface".
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

        // Pedallion orders. Keys are exactly the statuses this extension
        // itself names: the three PedallionExtension's unprocessedCounter
        // treats as waiting (pending, processing, awaiting_shipment) plus the
        // eight the pre-redesign view's own badge match enumerated. Pedallion
        // publishes its status list through GET /references, so an operator
        // can fetch a status this map has never heard of; that falls through
        // to neutral and prints itself legibly, which is the deliberate
        // behaviour rather than a gap.
        //
        // NO status here takes the accent, and that is a fact about the page
        // rather than an oversight: extensions/pedallion/routes/web.php
        // declares no order write route at all (its own comment says so), so
        // there is nothing on this surface that is the operator's move and
        // spending the accent would be spending it on nothing. Same call
        // shopee.returns and reports.payouts already made.
        //
        // The four seller-side stages take warning rather than info because
        // the party holding the order up is the seller's own desk, which is
        // exactly the line this file draws between the two tones.
        'pedallion.orders' => [
            'PENDING'            => 'warning',
            'PROCESSING'         => 'warning',
            'AWAITING_SHIPMENT'  => 'warning',
            'READY_TO_SHIP'      => 'warning',
            'SHIPPED'            => 'info',
            'DELIVERED'          => 'neutral',
            'COMPLETED'          => 'neutral',
            'PAID'               => 'neutral',
            'CANCELLED'          => 'danger',
            'REFUNDED'           => 'danger',
            'FAILED'             => 'danger',
        ],

        // Shopify carries TWO independent status vocabularies on one order,
        // which is why it gets two surfaces rather than one: a payment state
        // and a fulfilment state, and an order can be paid and unfulfilled or
        // unpaid and fulfilled. Merging them would mean inventing a combined
        // status Shopify never sends.
        //
        // Keys are exactly the seven values ShopifyOrderController::index()
        // offers as a financial filter, which are Shopify's own documented
        // financial_status set.
        //
        // NO accent here. This is the record of what the buyer's money has
        // done; the page's own accent belongs to the fulfilment column below,
        // which is the column a packer actually reads, and one page spends
        // the accent once.
        'shopify.payment' => [
            'PAID'               => 'neutral',
            'AUTHORIZED'         => 'warning',
            'PENDING'            => 'warning',
            'PARTIALLY_PAID'     => 'warning',
            'REFUNDED'           => 'danger',
            'PARTIALLY_REFUNDED' => 'danger',
            'VOIDED'             => 'danger',
        ],

        // Shopify fulfilment. Shopify sends NULL for an unfulfilled order
        // rather than a word, and the controller's own filter spells that
        // null as the string "unfulfilled", so that is the key here too and
        // the view normalises a null to it before asking.
        //
        // The accent is spent on the two states with goods still to go out.
        // This list is the seller's packing list: it is why a fulfilment
        // operator opens the page, and it is what the channel's own
        // unprocessed counter on the fulfilment hub is counting.
        'shopify.fulfilment' => [
            'UNFULFILLED' => 'success',
            'PARTIAL'     => 'success',
            'FULFILLED'   => 'neutral',
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

        // Purchase orders. Keys are exactly the seven constants on
        // Extensions\purchasing\Models\PurchaseOrder::STATUSES, nothing
        // invented, and the tones follow that model's own TRANSITIONS table
        // rather than a guess at what the words mean.
        //
        // Not OrderStatusTone: that class matches on the words a sales
        // `order_status` row carries (complete, shipped, pending) and knows
        // none of these seven. Every purchasing status but "cancelled" would
        // fall through it to neutral, which is a status column with no signal
        // at all. This vocabulary is genuinely separate, so it gets its own
        // surface here, which is also the class that already has the
        // surface-keyed shape and the tone philosophy written down.
        //
        // Two statuses take the accent, and only two:
        //   approved            nothing blocks you, go place the order with
        //                       the vendor (or email it, which on this
        //                       extension IS placing it).
        //   partially_received  goods are physically on the desk and the job
        //                       is half done. Finish receiving.
        // draft is deliberately NOT accent. A draft is a scratchpad, not a
        // commitment, and drafts accumulate: accenting them would put most of
        // a busy list in the accent colour and leave the two states that
        // genuinely need a hand indistinguishable from the noise.
        // ordered is warning rather than info because the vendor owes you
        // goods and a vendor is someone you can chase, which is exactly the
        // line this file's philosophy draws between the two tones.
        // received recedes; cancelled and voided both ended badly.
        'purchasing.orders' => [
            'DRAFT'              => 'neutral',
            'APPROVED'           => 'success',
            'ORDERED'            => 'warning',
            'PARTIALLY_RECEIVED' => 'success',
            'RECEIVED'           => 'neutral',
            'CANCELLED'          => 'danger',
            'VOIDED'             => 'danger',
        ],

        // Warehouse transfers. The lifecycle is written down in CLAUDE.md and
        // enforced by WarehouseTransferController, which refuses every
        // transition from the wrong state:
        //
        //   draft        editable, deletable, no stock has moved
        //   in_progress  locked, no stock has moved yet, cancellable
        //   completed    stock has moved, voidable (a void reverses it)
        //   cancelled / voided   terminal
        //
        // Exactly one status takes the accent: in_progress. That is the only
        // state with an unfinished physical job behind it - somebody has
        // committed to moving goods and the stock has not moved yet, so the
        // system and the shelf disagree until a human finishes it. It is also
        // the only state the operator cannot leave alone: draft can sit
        // forever harmlessly, completed is done.
        //
        // draft is neutral for the same reason it is on purchase orders: it is
        // a scratchpad, drafts accumulate, and accenting them would drown the
        // one state that genuinely needs a hand.
        // completed recedes rather than taking success, because "the stock
        // moved three weeks ago" and "move this stock now" must not be the
        // same colour. cancelled and voided both ended the record.
        'warehousing.transfers' => [
            'DRAFT'       => 'neutral',
            'IN_PROGRESS' => 'success',
            'COMPLETED'   => 'neutral',
            'CANCELLED'   => 'danger',
            'VOIDED'      => 'danger',
        ],

        // Marketplace review -> OpenCart (and Venta) push state, from
        // marketplace_reviews.oc_sync_status / venta_sync_status. Only
        // `pending` and `error` are ever actionable: ReviewController's push
        // and skip both refuse anything else outright.
        //
        // One accent, on `pending`: a review sitting unpushed is the only
        // state here with a job waiting for a person. `pushed` recedes to
        // neutral (done is not an instruction), `skipped` is neutral because
        // somebody already decided, and `error` is danger because the push
        // was attempted and failed.
        'opencart.reviews' => [
            'PENDING' => 'success',
            'PUSHED'  => 'neutral',
            'SKIPPED' => 'neutral',
            'ERROR'   => 'danger',
        ],

        // Marketplace payout state on the payouts report. Derived either
        // from the synced payout_status column or, when that is still null,
        // from the marketplace order status (ReportController::derivePayoutStatus).
        //
        // NO status here takes the accent: the payouts report has no action
        // on it at all. It is a read-only reconciliation view, so there is
        // nothing that is "the operator's move" and spending the accent
        // would be spending it on nothing. Money still owed is warning
        // (chaseable), money in flight is info (not chaseable), money
        // settled is neutral, money that will never arrive is danger.
        'reports.payouts' => [
            'PAID'       => 'neutral',
            'RELEASING'  => 'info',
            'IN_TRANSIT' => 'info',
            'UNPAID'     => 'warning',
            'CANCELLED'  => 'danger',
            'RETURNED'   => 'danger',
            'UNKNOWN'    => 'neutral',
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

        // Pedallion's statuses arrive as lowercase slugs from its own
        // /references endpoint. Each one is said in the words a packing desk
        // uses, the same words the other channels' labels use for the same
        // physical state, so "shipped" reads identically wherever it appears.
        'pedallion.orders' => [
            'PENDING'            => 'To process',
            'PROCESSING'         => 'Processing',
            'AWAITING_SHIPMENT'  => 'To pack',
            'READY_TO_SHIP'      => 'To hand over',
            'SHIPPED'            => 'Shipped',
            'DELIVERED'          => 'Delivered',
            'COMPLETED'          => 'Completed',
            'PAID'               => 'Paid',
            'CANCELLED'          => 'Cancelled',
            'REFUNDED'           => 'Refunded',
            'FAILED'             => 'Failed',
        ],

        // Shopify's own constants read almost as English already; what they
        // needed was consistent casing and the two multi-word ones saying
        // what they mean rather than joining two words with an underscore.
        'shopify.payment' => [
            'PAID'               => 'Paid',
            'AUTHORIZED'         => 'Authorised',
            'PENDING'            => 'Unpaid',
            'PARTIALLY_PAID'     => 'Partly paid',
            'REFUNDED'           => 'Refunded',
            'PARTIALLY_REFUNDED' => 'Partly refunded',
            'VOIDED'             => 'Voided',
        ],

        // "Unfulfilled" is Shopify's word for "nothing has shipped", which is
        // "To pack" in the words this application already uses for that state
        // on Lazada and TikTok. "Partly packed" says which half is done.
        'shopify.fulfilment' => [
            'UNFULFILLED' => 'To pack',
            'PARTIAL'     => 'Partly packed',
            'FULFILLED'   => 'Packed',
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

        // Said the way a buyer says them. The only constant that does not
        // read as English on its own is partially_received, which the old
        // views printed as "Partially Received" via a str_replace on the
        // underscore. "Partly received" is what someone actually says, and
        // it matches "Partly paid" on the sales order detail page.
        'purchasing.orders' => [
            'DRAFT'              => 'Draft',
            'APPROVED'           => 'Approved',
            'ORDERED'            => 'Ordered',
            'PARTIALLY_RECEIVED' => 'Partly received',
            'RECEIVED'           => 'Received',
            'CANCELLED'          => 'Cancelled',
            'VOIDED'             => 'Voided',
        ],

        // The pre-redesign views disagreed with each other about what
        // `in_progress` is called: the transfers list said "Approved", the
        // transfer record said "In Progress", and the same row therefore
        // changed its name when you clicked it. CLAUDE.md's lifecycle calls
        // it In Progress, so that is the one name, in the one place.
        'warehousing.transfers' => [
            'DRAFT'       => 'Draft',
            'IN_PROGRESS' => 'In progress',
            'COMPLETED'   => 'Completed',
            'CANCELLED'   => 'Cancelled',
            'VOIDED'      => 'Voided',
        ],

        // "Pending" on its own does not say pending what. Both review pages
        // sit under an "OpenCart sync" heading, so the label only has to
        // name the state, but "Not pushed" says the plain thing where
        // "Pending" leaves the reader to guess which side is waiting.
        'opencart.reviews' => [
            'PENDING' => 'Not pushed',
            'PUSHED'  => 'Pushed',
            'SKIPPED' => 'Skipped',
            'ERROR'   => 'Push failed',
        ],

        // The report already derives these as display strings; the map
        // exists so the words and the colour are decided in one place, and
        // so "In Transit" stops being title-cased differently from every
        // other status in the app.
        'reports.payouts' => [
            'PAID'       => 'Paid',
            'RELEASING'  => 'Releasing',
            'IN_TRANSIT' => 'In transit',
            'UNPAID'     => 'Unpaid',
            'CANCELLED'  => 'Cancelled',
            'RETURNED'   => 'Returned',
            'UNKNOWN'    => 'Unknown',
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
