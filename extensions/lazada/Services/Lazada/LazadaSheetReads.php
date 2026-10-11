<?php

namespace Extensions\Lazada\Services\Lazada;

use App\Channels\Listings\SheetReads;
use Extensions\Lazada\Controllers\LazadaProductController;
use Extensions\Lazada\Models\LazadaCategoryTemplate;
use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaProductGroup;

class LazadaSheetReads
{
    public function readMissing(object $store, LazadaClient $client, int $limit = SheetReads::PER_RUN): array
    {
        $region = (string) ($store->region ?? '');
        $wanted = LazadaListing::query()->whereNotNull('primary_category_id')->distinct()->pluck('primary_category_id')
            ->merge(LazadaProductGroup::query()->whereNotNull('lazada_category_id')->distinct()->pluck('lazada_category_id'))
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->unique()->values()->all();
        $cached = $wanted
            ? LazadaCategoryTemplate::query()->where('region', $region)->whereIn('primary_category_id', $wanted)
                ->whereNotNull('template_body')->pluck('primary_category_id')->all()
            : [];
        $reader = app(LazadaProductController::class);

        return SheetReads::run($wanted, $cached, fn (string $id) => (bool) ($reader->readCategoryTemplate((int) $id, $client)['ok'] ?? false), $limit);
    }
}
