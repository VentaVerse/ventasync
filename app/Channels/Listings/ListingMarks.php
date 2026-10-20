<?php

namespace App\Channels\Listings;

use App\Models\WatermarkTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ListingMarks
{
    private array $templates = [];

    private array $byProduct = [];

    public function __construct(
        private string $channel,
        private int $storeId,
        array $productIds = [],
        string $listingsTable = '',
        string $storeColumn = '',
    ) {
        if ($productIds === [] || ! Schema::hasTable($listingsTable)) {
            return;
        }

        $own = DB::table($listingsTable)
            ->whereIn('product_id', $productIds)
            ->when($storeColumn !== '', fn ($q) => $q->where($storeColumn, $this->storeId))
            ->whereNotNull('watermark_template_id')
            ->pluck('watermark_template_id', 'product_id');

        foreach ($own as $productId => $templateId) {
            $this->byProduct[(int) $productId] = (int) $templateId;
        }
    }

    public function thumb(int $productId, ?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        return \App\Services\Media\ImageCache::markedUrl($path, $this->template($productId));
    }

    public function template(int $productId): ?WatermarkTemplate
    {
        $id = $this->byProduct[$productId] ?? null;
        if ($id === null) {
            return null;
        }

        $key = (string) $id;
        if (! array_key_exists($key, $this->templates)) {
            $this->templates[$key] = WatermarkTemplate::ofStore($this->channel, $this->storeId)->find($id);
        }

        return $this->templates[$key];
    }
}
