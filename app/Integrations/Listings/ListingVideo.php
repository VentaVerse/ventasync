<?php

namespace App\Integrations\Listings;

use App\Models\ProductVideo;
use App\Services\Media\ImageCache;
use App\Services\Media\ProductVideoFile;

final class ListingVideo
{
    public static function forListing(int $productId, ?object $listing): ?array
    {
        if ((bool) ($listing->video_off ?? false)) {
            return null;
        }

        $own = trim((string) ($listing->video_path ?? ''));
        if ($own !== '') {
            return self::describe($own, true);
        }

        $video = ProductVideo::query()->where('product_id', $productId)->first();

        return $video === null ? null : [
            'path' => (string) $video->path,
            'name' => (string) $video->original_name,
            'bytes' => (int) $video->bytes,
            'duration_ms' => $video->duration_ms === null ? null : (int) $video->duration_ms,
            'own' => false,
        ];
    }

    private static function describe(string $path, bool $own): ?array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }

        return [
            'path' => $path,
            'name' => basename($path),
            'bytes' => (int) $disk->size($path),
            'duration_ms' => ProductVideoFile::durationMs($disk->path($path)),
            'own' => $own,
        ];
    }

    public static function cardData(int $productId, ?object $listing): array
    {
        $video = self::forListing($productId, $listing);

        return [
            'video' => $video === null ? null : $video + [
                'url' => ImageCache::publicUrl($video['path']),
                'size' => ProductVideoFile::megabytes($video['bytes']),
                'length' => $video['duration_ms'] === null
                    ? null
                    : ProductVideoFile::seconds($video['duration_ms'] / 1000),
            ],
            'videoOff' => (bool) ($listing->video_off ?? false),
        ];
    }

    public static function submitted(\Illuminate\Http\Request $request): array
    {
        $path = trim((string) $request->input('video_path', ''));
        $ok = $path !== ''
            && ! str_contains($path, '..')
            && str_starts_with($path, ProductVideoFile::DIR . '/')
            && \Illuminate\Support\Facades\Storage::disk('public')->exists($path);

        return [
            'video_path' => $ok ? $path : null,
            'video_off' => $request->input('video_off') === '1',
        ];
    }

}
