<?php

namespace App\Integrations\Listings;

use App\Models\WatermarkTemplate;
use App\Services\Media\ImageCache;
use App\Services\Media\StampedImages;
use App\Support\Catalog\ProductImages;

final class ListingImages
{
    public static function cardData(int $productId, ?array $stored, ?object $listing, ?int $groupTemplateId, string $integration, int $storeId): array
    {
        $submitted = null;
        $old = old('image_order');
        if ($old !== null && trim((string) $old) !== '') {
            $decoded = json_decode((string) $old, true);
            $submitted = is_array($decoded) ? $decoded : null;
        }

        $refused = session()->hasOldInput();
        $templateId = ((int) ($refused ? old('watermark_template_id') : ($listing->watermark_template_id ?? 0))) ?: null;
        $allImages = $refused ? (bool) old('watermark_all_images') : (bool) ($listing->watermark_all_images ?? false);
        $groupTemplateId = ((int) $groupTemplateId) ?: null;

        $paths = ProductImages::paths($productId, $submitted ?? $stored);
        $template = WatermarkTemplate::resolve($templateId, $groupTemplateId, $integration, $storeId);
        $stamper = app(StampedImages::class);
        $shown = function (string $path, int $i) use ($template, $allImages, $stamper): string {
            if ($template !== null && ($i === 0 || $allImages)) {
                $bytes = $stamper->bytes($path, $template, ImageCache::PREVIEW);
                if ($bytes !== null) {
                    return 'data:image/jpeg;base64,' . base64_encode($bytes);
                }
            }

            return ImageCache::url($path, ImageCache::PREVIEW) ?? ProductImages::url($path);
        };

        return [
            'tiles' => array_map(fn (string $path, int $i) => ['path' => $path, 'url' => $shown($path, $i)], $paths, array_keys($paths)),
            'catalogTiles' => ProductImages::tiles($productId),
            'browseUrl' => route('products.images.browse'),
            'watermarkOptions' => WatermarkTemplate::forStore($integration, $storeId),
            'watermarkTemplateId' => $templateId,
            'watermarkAllImages' => $allImages,
            'watermarkLent' => WatermarkTemplate::nameOf($groupTemplateId, $integration, $storeId),
            'imagesOff' => (function () use ($listing, $refused) {
                $off = $refused ? old('image_off') : ($listing->image_off ?? null);
                $off = is_string($off) ? json_decode($off, true) : $off;

                return is_array($off) ? array_values(array_filter($off, 'is_string')) : [];
            })(),
        ];
    }

    public static function submittedWatermark(\Illuminate\Http\Request $request, string $integration, int $storeId): array
    {
        return [
            'watermark_template_id' => WatermarkTemplate::idOrNull($request->input('watermark_template_id'), $integration, $storeId),
            'watermark_all_images' => $request->boolean('watermark_all_images'),
        ];
    }

    public static function submittedOff(int $productId, ?string $json): ?array
    {
        return self::submitted($productId, $json);
    }

    public static function sending(array $paths, ?object $listing): array
    {
        $off = $listing->image_off ?? null;
        $off = is_string($off) ? json_decode($off, true) : $off;
        if (! is_array($off) || $off === []) {
            return array_values($paths);
        }

        return array_values(array_filter($paths, fn (string $p) => ! in_array($p, $off, true)));
    }

    public static function submitted(int $productId, ?string $json): ?array
    {
        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $accepted = ProductImages::acceptable($decoded);

        return $accepted !== [] ? $accepted : null;
    }
}
