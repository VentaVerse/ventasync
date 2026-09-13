<?php

namespace App\Services\Media;

use App\Models\WatermarkTemplate;
use Illuminate\Support\Facades\Storage;

/**
 * A product's pictures as a listing sends them: marked with the listing's
 * watermark template, the main picture only unless the listing asks for
 * every picture.
 *
 * A marked picture is written once and kept, named by everything that
 * shapes it (the picture, the mark and the template's settings), so a
 * second push reuses the file and a change to any of them draws a new one.
 * A picture that cannot be marked goes up as it is: a mark is never a
 * reason to fail a push.
 */
final class StampedImages
{
    /** Outside catalog/, so marked copies never appear in the image library as pictures of their own. */
    public const DIR = 'watermarked';

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

        $out = [];
        foreach (array_values($paths) as $i => $path) {
            $out[] = ($i === 0 || $allImages) ? ($this->stamped($path, $template) ?? $path) : $path;
        }

        return $out;
    }

    /** The marked copy of one picture, or null when it cannot be marked. */
    public function stamped(string $path, WatermarkTemplate $template): ?string
    {
        $disk = Storage::disk('public');
        $path = ltrim($path, '/');
        if ($path === '' || ! $disk->exists($path) || ! is_file($template->markPath())) {
            return null;
        }

        $target = self::DIR . '/' . $this->key($path, $template) . '.jpg';
        if ($disk->exists($target)) {
            return $target;
        }

        $bytes = $template->stamp($disk->path($path));
        if ($bytes === null) {
            return null;
        }
        $disk->put($target, $bytes);

        return $target;
    }

    private function key(string $path, WatermarkTemplate $template): string
    {
        $disk = Storage::disk('public');
        $mark = $template->markPath();

        return hash('sha256', implode('|', [
            $path, $disk->size($path), $disk->lastModified($path),
            (string) $template->image_path, (string) @filesize($mark), (string) @filemtime($mark),
            (string) $template->position, (string) $template->size_percent, (string) $template->opacity,
            (string) $template->offset_x_percent, (string) $template->offset_y_percent,
        ]));
    }
}
