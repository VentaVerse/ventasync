<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Read the length from the MP4 mvhd header: the server has no ffmpeg and exec() is disabled.
final class ProductVideoFile
{
    public const DIR = 'video';

    public const MIME = ['video/mp4', 'video/quicktime'];
    public const EXTENSIONS = ['mp4', 'mov'];

    public static function stagingPrefix(): string
    {
        return self::DIR . '/_incoming/' . (string) (auth()->id() ?: 'anon') . '-';
    }

    public static function stagingKey(string $token): string
    {
        return substr(self::stagingPrefix() . preg_replace('/[^A-Za-z0-9_-]/', '', $token), strlen(self::DIR . '/_incoming/'), 120);
    }

    public static function put(UploadedFile $file, int $productId, string $stagingKey = ''): array
    {
        $dir = $productId > 0
            ? self::DIR . '/' . $productId
            : self::DIR . '/_incoming/' . ($stagingKey !== '' ? $stagingKey : self::stagingKey(''));

        $name = Str::uuid()->toString() . '.' . strtolower($file->getClientOriginalExtension() ?: 'mp4');
        $path = $file->storeAs($dir, $name, 'public');

        return [
            'path' => (string) $path,
            'original_name' => (string) $file->getClientOriginalName(),
            'bytes' => (int) Storage::disk('public')->size($path),
            'duration_ms' => self::durationMs(Storage::disk('public')->path($path)),
        ];
    }

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

    public static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

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

    public static function durationMs(string $absolute): ?int
    {
        $handle = @fopen($absolute, 'rb');
        if (! $handle) {
            return null;
        }

        try {
            $head = (string) fread($handle, 4000000);
        } finally {
            fclose($handle);
        }

        $at = strpos($head, 'mvhd');
        if ($at === false) {
            return null;
        }

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
