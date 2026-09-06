<?php

namespace App\Integrations\Listings;

use Carbon\Carbon;

/**
 * The one answer to "where does this product stand on this channel?"
 *
 * Phase B of the channels rebuild (#184). Before this, Shopee alone had
 * eleven status vocabularies: a mirror enum, four tab keys, three label
 * maps that already disagreed, readiness sentences other code matched by
 * substring, two pivot vocabularies sharing a name, reconcile verdicts,
 * and drift with no vocabulary at all. Every surface computed its own
 * answer, so pages could not agree - and a user can only trust a system
 * whose screens agree with each other.
 *
 * This object is computed by one engine per channel and RENDERED
 * everywhere: index rows, listing pages, bulk forms, dashboards, and the
 * All Listings grid to come. Surfaces read labels and tones from here and
 * never keep a map of their own.
 *
 * The states are the words an operator thinks in, not the marketplace's:
 *
 *   not_listed  the catalogue has it, the channel does not. ready says
 *               whether a push would succeed; missing lists what stands
 *               in the way, as coded gaps with human labels.
 *   live        selling now.
 *   inactive    on the channel but switched off (unlisted).
 *   reviewing   waiting on the marketplace's review. Nothing to do.
 *   attention   the channel needs a human: violation, deleted, vanished.
 *               reasons[] says why in a sentence.
 *   unknown     linked, but the mirror has never been read. Rendered as
 *               exactly that ("Not checked yet"), never as a guess.
 *
 * inSync is the drift verdict, independent of state: true (the channel
 * matches the catalogue as far as we can tell), false (the catalogue
 * changed since the last push), null (cannot say - never pushed from
 * here, or nothing to compare). A listing can be live AND out of date;
 * those are different facts and both are shown.
 */
final class ListingState
{
    public const NOT_LISTED = 'not_listed';
    public const LIVE = 'live';
    public const INACTIVE = 'inactive';
    public const REVIEWING = 'reviewing';
    public const ATTENTION = 'attention';
    public const UNKNOWN = 'unknown';

    /**
     * Gap codes a product can carry. Deep-links key on THESE, never on the
     * label text.
     *
     * THE HONESTY RULE (the owner, 2026-09-06): "Ready" has to be absolutely
     * sure. It means every check VentaSync runs before sending passes, and
     * the push paths refuse through the SAME readiness computation, so a
     * product that reads Ready can never be turned away by this side. What
     * only the marketplace knows (a rule it never published, a quota, an
     * image it dislikes) is not a readiness gap; it arrives as the push's
     * failure and lives in the Last sync column. Any refusal this side
     * could have known about and did not is a bug: add the gap.
     */
    public const GAP_CATEGORY = 'category';
    public const GAP_SKU = 'sku';
    public const GAP_COURIERS = 'couriers';
    public const GAP_IMAGE = 'image';
    public const GAP_SHEET = 'sheet';
    public const GAP_PARCEL = 'parcel';
    public const GAP_ATTRIBUTES = 'attributes';
    public const GAP_DISABLED = 'disabled';
    public const GAP_NAME = 'name';
    public const GAP_DESCRIPTION = 'description';
    public const GAP_PRICE = 'price';
    public const GAP_VARIATIONS = 'variations';

    /**
     * @param list<string> $reasons  plain sentences for attention / drift
     * @param list<array{code: string, label: string}> $missing  gaps before a push can succeed;
     *        for a listed product, before an update can (the update's own checklist)
     * @param ?string $attentionLabel  the channel's own word for an attention
     *        state ("Violation", "Rejected") - supplied by the channel engine,
     *        because only the channel knows its marketplace's vocabulary.
     * @param ?string $inactiveLabel  the channel's own word for a listing the
     *        seller took down (the owner, 2026-09-06: mirror the marketplace -
     *        Shopee "Unpublished", TikTok "Deactivated", Lazada "Inactive");
     *        "Unlisted" when the channel has no word of its own.
     */
    public function __construct(
        public readonly string $state,
        public readonly ?bool $inSync = null,
        public readonly array $reasons = [],
        public readonly bool $ready = false,
        public readonly array $missing = [],
        public readonly ?Carbon $checkedAt = null,
        public readonly ?Carbon $pushedAt = null,
        public readonly ?string $channelStatusRaw = null,
        public readonly ?string $attentionLabel = null,
        public readonly ?string $inactiveLabel = null,
    ) {
    }

    /**
     * THE label and tone table. The only one. Three copies of this map
     * existed for Shopee alone and had already drifted apart.
     *
     * @return array{0: string, 1: string} [label, badge tone]
     */
    public function badge(): array
    {
        return match ($this->state) {
            self::NOT_LISTED => ['Not listed', 'neutral'],
            self::LIVE => ['Live', 'success'],
            self::INACTIVE => [$this->inactiveLabel ?? 'Unlisted', 'neutral'],
            self::REVIEWING => ['Under review', 'warning'],
            self::ATTENTION => [$this->attentionLabel ?? 'Needs attention', 'danger'],
            default => ['Not checked yet', 'neutral'],
        };
    }

    public function label(): string
    {
        return $this->badge()[0];
    }

    public function tone(): string
    {
        return $this->badge()[1];
    }

    /** The drift chip beside a live/inactive badge, or null when there is nothing to say. */
    public function driftLabel(): ?string
    {
        return $this->inSync === false ? 'Catalogue changed' : null;
    }

    /** The gap labels as one readable list ("a Shopee category, at least one courier"). */
    public function missingSummary(): string
    {
        return implode(', ', array_map(fn ($gap) => $gap['label'], $this->missing));
    }

    /** The Ready column's badge: [label, tone]. */
    public function readyBadge(): array
    {
        return $this->ready ? ['Ready', 'success'] : ['Not ready', 'warning'];
    }
}
