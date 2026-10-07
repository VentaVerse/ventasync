<?php

namespace App\Integrations\Listings;

use Carbon\Carbon;

final class ListingState
{
    public const NOT_LISTED = 'not_listed';
    public const LIVE = 'live';
    public const INACTIVE = 'inactive';
    public const REVIEWING = 'reviewing';
    public const ATTENTION = 'attention';
    public const UNKNOWN = 'unknown';

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

    public function driftLabel(): ?string
    {
        return $this->inSync === false ? 'Catalog change' : null;
    }

    public function missingSummary(): string
    {
        return implode(', ', array_map(fn ($gap) => $gap['label'], $this->missing));
    }

    public function readyBadge(): array
    {
        return $this->ready ? ['Eligible', 'success'] : ['Needs details', 'warning'];
    }
}
