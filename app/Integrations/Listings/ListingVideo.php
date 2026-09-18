<?php

namespace App\Integrations\Listings;

use App\Models\ProductVideo;
use App\Services\Media\ImageCache;
use App\Services\Media\ProductVideoFile;

/**
 * THE VIDEO A LISTING SENDS.
 *
 * The catalog's video is the source, as the picture library is. A listing
 * sends it unless it says otherwise: its own video instead, or none at all.
 *
 * NOTHING HERE KNOWS WHAT A CHANNEL WILL TAKE. A marketplace changes its
 * limits without telling anyone, so a limit written down here would one day
 * refuse a video the store would have accepted, and the seller could not tell
 * our guess from the store's answer. The video is sent and the store answers;
 * its refusal reaches the seller the way every other push failure does.
 *
 * What this class is for is the other half: the channels disagree, so a store
 * that will not take the catalog's video is given its own cut, and the catalog
 * and the other channels are left alone.
 */
final class ListingVideo
{
    /**
     * The video this listing sends: its own, else the catalog's, else none.
     *
     * @return ?array{path: string, name: string, bytes: int, duration_ms: ?int, own: bool}
     */
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

    /** A file on the disk, measured now, for a video a listing carries itself. */
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

    /** What the card needs, ready to spread into a view's data. */
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

    /**
     * A listing's video choice as submitted, ready to store.
     *
     * A path is kept only when the file is really there and really a video:
     * a hidden input is client input, and this one is read at push time.
     *
     * @return array{video_path: ?string, video_off: bool}
     */
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
