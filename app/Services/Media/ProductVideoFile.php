<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * THE ONE VIDEO A PRODUCT CARRIES, AND WHAT THE STORES WILL SAY ABOUT IT.
 *
 * It lives under video/ on the public disk, beside catalog/ rather than in
 * it, so the picture library never lists it and a store that fetches by URL
 * can still reach it.
 *
 * The three stores disagree, so the seller is told at upload rather than at
 * push, and nothing is refused on their behalf:
 *
 *   Shopee        10 to 60 seconds, 30 MB
 *   Lazada        up to 100 MB, and a per-seller quota
 *   TikTok Shop   up to 100 MB, 9:16 to 16:9, 20 to 60 seconds recommended
 *
 * How long it runs is read from the file's own header (an MP4 keeps it in
 * the mvhd atom), because the server has no ffmpeg and exec() is off.
 */
final class ProductVideoFile
{
    public const DIR = 'video';

    /** What every store takes: the strictest of the three. */
    public const MAX_BYTES = 30000000;
    public const MIN_SECONDS = 10;
    public const MAX_SECONDS = 60;

    /** What the upload accepts at all, so a refusal is about the stores, not the format. */
    public const MIME = ['video/mp4', 'video/quicktime'];
    public const EXTENSIONS = ['mp4', 'mov'];

    /** Store the file for a product, or under a token when the product has no id yet. */
    public static function put(UploadedFile $file, int $productId, string $token = ''): array
    {
        $dir = $productId > 0
            ? self::DIR . '/' . $productId
            : self::DIR . '/_incoming/' . preg_replace('/[^A-Za-z0-9_-]/', '', $token);

        $name = Str::uuid()->toString() . '.' . strtolower($file->getClientOriginalExtension() ?: 'mp4');
        $path = $file->storeAs($dir, $name, 'public');

        return [
            'path' => (string) $path,
            'original_name' => (string) $file->getClientOriginalName(),
            'bytes' => (int) Storage::disk('public')->size($path),
            'duration_ms' => self::durationMs(Storage::disk('public')->path($path)),
        ];
    }

    /** Move a video staged under a token into the product's own folder. */
    public static function settle(string $path, int $productId): string
    {
        $disk = Storage::disk('public');
        if (! str_starts_with($path, self::DIR . '/_incoming/') || ! $disk->exists($path)) {
            return $path;
        }

        $target = self::DIR . '/' . $productId . '/' . basename($path);
        $disk->makeDirectory(dirname($target));
        $disk->move($path, $target);

        return $target;
    }

    /** Remove a video and the folder it leaves behind when it was the only one. */
    public static function forget(?string $path): void
    {
        $disk = Storage::disk('public');
        $path = trim((string) $path);
        if ($path === '' || ! str_starts_with($path, self::DIR . '/') || str_contains($path, '..')) {
            return;
        }
        $disk->delete($path);
        $folder = dirname($path);
        if ($folder !== self::DIR && $disk->files($folder) === [] && $disk->directories($folder) === []) {
            $disk->deleteDirectory($folder);
        }
    }

    /**
     * The stores that will refuse this video, each with the reason, so the
     * seller reads it at upload rather than after a push.
     *
     * @return list<string>
     */
    public static function refusals(int $bytes, ?int $durationMs): array
    {
        $out = [];
        $seconds = $durationMs !== null ? $durationMs / 1000 : null;

        if ($bytes > self::MAX_BYTES) {
            $out[] = 'Shopee refuses a video over 30 MB. This one is ' . self::megabytes($bytes) . '.';
        }
        if ($seconds !== null && $seconds < self::MIN_SECONDS) {
            $out[] = 'Shopee refuses a video under 10 seconds. This one runs ' . self::seconds($seconds) . '.';
        }
        if ($seconds !== null && $seconds > self::MAX_SECONDS) {
            $out[] = 'Shopee refuses a video over 60 seconds. This one runs ' . self::seconds($seconds) . '.';
        }

        return $out;
    }

    /** "30.4 MB" */
    public static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    /** "1 minute 4 seconds", "8 seconds" */
    public static function seconds(float $seconds): string
    {
        $whole = (int) round($seconds);
        if ($whole < 60) {
            return $whole . ' ' . Str::plural('second', $whole);
        }
        $minutes = intdiv($whole, 60);
        $rest = $whole % 60;

        return $minutes . ' ' . Str::plural('minute', $minutes)
            . ($rest > 0 ? ' ' . $rest . ' ' . Str::plural('second', $rest) : '');
    }

    /**
     * How long the file says it runs, in milliseconds, or null when its
     * header does not say. MP4 and MOV share the format: a `mvhd` atom
     * carries a timescale and a duration in that scale.
     */
    public static function durationMs(string $absolute): ?int
    {
        $handle = @fopen($absolute, 'rb');
        if (! $handle) {
            return null;
        }

        try {
            // The atom sits early in the file; a few MB is more than enough.
            $head = (string) fread($handle, 4000000);
        } finally {
            fclose($handle);
        }

        $at = strpos($head, 'mvhd');
        if ($at === false) {
            return null;
        }

        /* After the atom's name: one version byte, three flag bytes, then
           created and modified (4 bytes each at version 0, 8 at version 1),
           then the timescale and the duration in it. */
        $version = ord($head[$at + 4] ?? "\0");
        if ($version === 1) {
            $timescale = unpack('N', substr($head, $at + 24, 4))[1] ?? 0;
            $high = unpack('N', substr($head, $at + 28, 4))[1] ?? 0;
            $low = unpack('N', substr($head, $at + 32, 4))[1] ?? 0;
            $duration = ($high << 32) + $low;
        } else {
            $timescale = unpack('N', substr($head, $at + 16, 4))[1] ?? 0;
            $duration = unpack('N', substr($head, $at + 20, 4))[1] ?? 0;
        }

        if ($timescale <= 0 || $duration <= 0) {
            return null;
        }

        return (int) round($duration / $timescale * 1000);
    }
}
