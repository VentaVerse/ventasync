<?php

namespace App\Services\Media;

use App\Models\WatermarkTemplate;

final class ListingWatermark
{
    public static function paths(array $paths, ?object $listing, string $channel, int $storeId): array
    {
        $template = WatermarkTemplate::resolve(((int) ($listing->watermark_template_id ?? 0)) ?: null, $channel, $storeId);

        return app(StampedImages::class)->paths($paths, $template, (bool) ($listing->watermark_all_images ?? false));
    }
}
