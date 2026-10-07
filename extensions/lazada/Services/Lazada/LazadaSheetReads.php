<?php

namespace Extensions\lazada\Services\Lazada;

use App\Integrations\Listings\SheetReads;
use Extensions\lazada\Controllers\LazadaProductController;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductGroup;

class LazadaSheetReads
{
    public function readMissing(object $setting, LazadaClient $client, int $limit = SheetReads::PER_RUN): array
    {
        $region = (string) ($setting->region ?? '');
        $wanted = LazadaProduct::query()->whereNotNull('primary_category_id')->distinct()->pluck('primary_category_id')
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
