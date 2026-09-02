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

    /** Gap codes a not_listed product can carry. Deep-links key on THESE, never on the label text. */
    public const GAP_CATEGORY = 'category';
    public const GAP_COURIERS = 'couriers';
    public const GAP_IMAGE = 'image';
    public const GAP_SHEET = 'sheet';
    public const GAP_ATTRIBUTES = 'attributes';

    /**
     * @param list<string> $reasons  plain sentences for attention / drift
     * @param list<array{code: string, label: string}> $missing  gaps before a push can succeed
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
            self::INACTIVE => ['Unlisted', 'neutral'],
            self::REVIEWING => ['Under review', 'warning'],
            self::ATTENTION => [$this->attentionLabel(), 'danger'],
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

    /** Attention states keep the marketplace's own vocabulary where sellers already know it. */
    private function attentionLabel(): string
    {
        return match ($this->channelStatusRaw) {
            'BANNED' => 'Violation',
            'SELLER_DELETE' => 'Deleted',
            'SHOPEE_DELETE' => 'Deleted by Shopee',
            'MISSING' => 'Not found on Shopee',
            default => 'Needs attention',
        };
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
}
