<?php

namespace App\Services\Media;

use App\Models\WatermarkTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A product's pictures as a listing sends them: marked with the listing's
 * watermark template, the main picture only unless the listing asks for
 * every picture.
 *
 * The mark goes on the picture's push copy (ImageCache), so it sits where
 * the buyer sees it on the square the store shows. A marked picture is not
 * kept: it is written for the push under tmp/push, where a store that
 * fetches by URL (Lazada, WooCommerce, Venta) reads it during the push, and
 * a store sent the file itself (Shopee, TikTok Shop) is sent it from there.
 * Every store keeps its own copy, so these files are removed an hour later.
 *
 * A picture that cannot be marked goes up as its push copy, or as it is: a
 * mark is never a reason to fail a push.
 */
final class StampedImages
{
    public const DIR = 'tmp/push';

    /** How long a stamped picture stays, well past the push that wrote it. */
    private const KEEP_SECONDS = 3600;

    /**
     * The pictures to send, in the same order, each a path on the public
     * disk. The first picture is the main one.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function paths(array $paths, ?WatermarkTemplate $template, bool $allImages): array
    {
        if ($template === null) {
            return array_values($paths);
        }

        $this->prune();
        $out = [];
        foreach (array_values($paths) as $i => $path) {
            $out[] = ($i === 0 || $allImages) ? ($this->stamped($path, $template) ?? $path) : $path;
        }

        return $out;
    }

    /** The marked picture written for this push, or null when it cannot be marked. */
    public function stamped(string $path, WatermarkTemplate $template): ?string
    {
        $bytes = $this->bytes($path, $template);
        if ($bytes === null) {
            return null;
        }

        $target = self::DIR . '/' . Str::uuid()->toString() . '.jpg';
        Storage::disk('public')->put($target, $bytes);

        return $target;
    }

    /**
     * The marked push copy as JPEG bytes, kept nowhere, or null when the
     * picture or the mark cannot be read.
     */
    public function bytes(string $path, WatermarkTemplate $template, string $kind = ImageCache::PUSH): ?string
    {
        if (! is_file($template->markPath())) {
            return null;
        }
        $square = ImageCache::path($path, $kind);
        if ($square === null) {
            return null;
        }

        $bytes = $template->stamp(Storage::disk('public')->path($square));

        return $bytes !== null ? ImageCache::compressed($bytes, ImageCache::PUSH_MAX_BYTES) : null;
    }

    /** Stamped pictures from pushes that have long finished. */
    private function prune(): void
    {
        $disk = Storage::disk('public');
        $cutoff = time() - self::KEEP_SECONDS;
        foreach ($disk->files(self::DIR) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }
}
