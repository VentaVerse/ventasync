<?php

namespace Extensions\TikTok\Services\TikTok;

use App\Channels\Listings\SheetReads;
use Extensions\TikTok\Models\TikTokCategoryTemplate;
use Extensions\TikTok\Models\TikTokListing;

class TikTokSheetReads
{
    public function __construct(private TikTokAttributes $attributes) {}

    public function readMissing(array $c, TikTokClient $client, int $limit = SheetReads::PER_RUN): array
    {
        $wanted = TikTokListing::query()->whereNotNull('tiktok_category_id')->distinct()->pluck('tiktok_category_id')
            ->merge(\Extensions\TikTok\Models\TikTokListingTemplate::query()->whereNotNull('tiktok_category_id')->distinct()->pluck('tiktok_category_id'))
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => preg_match('/^\d+$/', $v) === 1)
            ->unique()->values()->all();
        $cached = $wanted ? TikTokCategoryTemplate::query()->whereIn('category_id', $wanted)->pluck('category_id')->all() : [];

        return SheetReads::run($wanted, $cached, fn (string $id) => $this->attributes->refreshTemplate($id, $client, $c) !== null, $limit);
    }
}
