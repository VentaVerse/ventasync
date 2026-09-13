<?php

namespace App\Services\Media;

use App\Models\WatermarkTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A listing's pictures as it sends them, marked by the watermark template it
 * names, or by the one its product group lends when it names none: the main
 * picture only, or every picture when the listing ticks "Apply to all images
 * in a product".
 *
 * Every channel calls this where it builds the pictures a push sends, and
 * each passes its own table names, so nothing here knows a channel.
 */
final class ListingWatermark
{
    /**
     * @param  list<string>  $paths  the pictures in the order they go up, main first
     * @param  ?object  $listing  the listing row, for its template and its "all images" tick
     * @return list<string>
     */
    public static function paths(array $paths, ?object $listing, ?int $groupTemplateId): array
    {
        $template = WatermarkTemplate::resolve(((int) ($listing->watermark_template_id ?? 0)) ?: null, $groupTemplateId);

        return app(StampedImages::class)->paths($paths, $template, (bool) ($listing->watermark_all_images ?? false));
    }

    /**
     * The template a product's product group lends, in the given store when
     * the store is known.
     */
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
