<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Integrations\Listings\SheetReads;
use Extensions\tiktok\Models\TikTokCategoryTemplate;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;

class TikTokSheetReads
{
    public function __construct(private TikTokAttributes $attributes) {}

    public function readMissing(array $c, TikTokClient $client, int $limit = SheetReads::PER_RUN): array
    {
        $wanted = TikTokListing::query()->whereNotNull('tiktok_category_id')->distinct()->pluck('tiktok_category_id')
            ->merge(TikTokProductGroup::query()->whereNotNull('tiktok_category_id')->distinct()->pluck('tiktok_category_id'))
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => preg_match('/^\d+$/', $v) === 1)
            ->unique()->values()->all();
        $cached = $wanted ? TikTokCategoryTemplate::query()->whereIn('category_id', $wanted)->pluck('category_id')->all() : [];

        return SheetReads::run($wanted, $cached, fn (string $id) => $this->attributes->refreshTemplate($id, $client, $c) !== null, $limit);
    }
}
