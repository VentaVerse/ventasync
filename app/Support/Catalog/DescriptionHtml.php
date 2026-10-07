<?php

namespace App\Support\Catalog;

use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Storage;

// Never throw here: a failed picture write must not lose the operator's saved words.
final class DescriptionHtml
{
    private const DIR = 'catalog/_pasted';

    private const TYPES = ['png' => 'png', 'jpeg' => 'jpg', 'jpg' => 'jpg', 'gif' => 'gif', 'webp' => 'webp'];

    public static function store(?string $html): string
    {
        return self::pastedImagesToFiles(self::forEditor($html));
    }

    public static function forEditor(?string $html): string
    {
        return self::librarySrcs(HtmlSanitizer::sanitize(self::decoded((string) $html)));
    }

    public static function decoded(string $html): string
    {
        if (preg_match('#<[a-z!/]#i', $html) || ! preg_match('#&lt;/?[a-z][a-z0-9]*(?:\s|/|&gt;)#i', $html)) {
            return $html;
        }

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function librarySrcs(string $html): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#(<img\b[^>]*?\bsrc=")([^"]*)(")#i',
            function (array $m): string {
                $url = self::bareSrcToUrl(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                return $url === null ? $m[0] : $m[1] . e($url) . $m[3];
            },
            $html
        );
    }

    private static function bareSrcToUrl(string $src): ?string
    {
        $src = trim($src);
        if ($src === '' || preg_match('#^([a-z][a-z0-9+.\-]*:|/|\#|\?)#i', $src)) {
            return null;
        }

        preg_match('/^([^?#]*)(.*)$/s', $src, $parts);
        $path = preg_replace('#^(\./)+#', '', $parts[1]);
        if ($path === '' || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        $encoded = implode('/', array_map(
            fn (string $segment) => rawurlencode(rawurldecode($segment)),
            explode('/', $path)
        ));

        return (str_starts_with($path, 'catalog/') ? asset('storage/' . $encoded) : asset($encoded)) . $parts[2];
    }

    public static function pastedImagesToFiles(string $html): string
    {
        if (! str_contains($html, 'data:image/')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#(<img\b[^>]*\bsrc=")(data:image/([a-z]+);base64,([A-Za-z0-9+/=\s]+))(")#i',
            function (array $m): string {
                $url = self::write(strtolower($m[3]), $m[4]);

                return $url === null ? $m[0] : $m[1] . e($url) . $m[5];
            },
            $html
        );
    }

    public static function imageUrls(string $html): array
    {
        if (! preg_match_all('#<img\b[^>]*\bsrc="([^"]+)"#i', $html, $m)) {
            return [];
        }

        return array_values(array_filter(array_map('html_entity_decode', $m[1])));
    }

    private static function write(string $type, string $base64): ?string
    {
        $ext = self::TYPES[$type] ?? null;
        if ($ext === null) {
            return null;
        }

        $bytes = base64_decode(preg_replace('/\s+/', '', $base64), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > 12 * 1024 * 1024) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }

        $path = self::DIR . '/' . date('Y') . '/' . substr(sha1($bytes), 0, 20) . '.' . $ext;

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                $disk->put($path, $bytes);
            }
        } catch (\Throwable) {
            return null;
        }

        return ProductImages::url($path);
    }
}
