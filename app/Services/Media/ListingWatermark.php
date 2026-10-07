<?php

namespace App\Services\Media;

use App\Models\WatermarkTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ListingWatermark
{
    public static function paths(array $paths, ?object $listing, ?int $groupTemplateId, string $integration, int $storeId): array
    {
        $template = WatermarkTemplate::resolve(((int) ($listing->watermark_template_id ?? 0)) ?: null, $groupTemplateId, $integration, $storeId);

        return app(StampedImages::class)->paths($paths, $template, (bool) ($listing->watermark_all_images ?? false));
    }

    public static function groupTemplateId(string $groupsTable, string $membersTable, string $groupKey, int $productId, ?string $storeColumn = null, ?int $storeId = null): ?int
    {
        if (! Schema::hasTable($groupsTable) || ! Schema::hasTable($membersTable) || ! Schema::hasColumn($groupsTable, 'watermark_template_id')) {
            return null;
        }

        $query = DB::table($membersTable . ' as m')
            ->join($groupsTable . ' as g', 'g.id', '=', 'm.' . $groupKey)
            ->where('m.product_id', $productId)
            ->whereNotNull('g.watermark_template_id');
        if ($storeColumn !== null && $storeId) {
            $query->where('g.' . $storeColumn, $storeId);
        }

        return ((int) $query->orderBy('m.id')->value('g.watermark_template_id')) ?: null;
    }
}
