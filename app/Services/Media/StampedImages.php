<?php

namespace App\Services\Media;

use App\Models\WatermarkTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StampedImages
{
    public const DIR = 'tmp/push';

    private const KEEP_SECONDS = 3600;

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
