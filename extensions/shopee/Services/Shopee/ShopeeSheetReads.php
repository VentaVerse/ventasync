<?php

namespace Extensions\shopee\Services\Shopee;

use App\Integrations\Listings\SheetReads;
use Extensions\shopee\Controllers\ShopeeProductGroupController;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroup;

class ShopeeSheetReads
{
    public function readMissing(array $auth, ShopeeClient $client, int $limit = SheetReads::PER_RUN): array
    {
        $wanted = ShopeeListing::query()->whereNotNull('shopee_category_id')->distinct()->pluck('shopee_category_id')
            ->merge(ShopeeProductGroup::query()->whereNotNull('shopee_category_id')->distinct()->pluck('shopee_category_id'))
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->unique()->values()->all();
        $cached = $wanted ? ShopeeCategoryTemplate::query()->whereIn('category_id', $wanted)->pluck('category_id')->all() : [];
        $groups = app(ShopeeProductGroupController::class);

        return SheetReads::run($wanted, $cached, function (string $id) use ($groups, $client, $auth) {
            $groups->ensureTemplate((int) $id, $client, $auth);

            return ShopeeCategoryTemplate::query()->where('category_id', (int) $id)->exists();
        }, $limit);
    }
}
