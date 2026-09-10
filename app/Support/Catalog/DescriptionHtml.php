<?php

namespace App\Support\Catalog;

use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Storage;

/**
 * A DESCRIPTION ON ITS WAY INTO THE CATALOG, AND THE PICTURES INSIDE IT.
 *
 * HtmlSanitizer deliberately allows `data:image/...;base64,` in an img src:
 * paste a screenshot into the editor and the browser hands over the whole
 * picture as text, and refusing it there would have thrown the paste away.
 * So it is stored - and a picture stored as text is a picture no
 * marketplace can ever take. Shopee, Lazada and TikTok all want a URL they
 * can fetch or bytes they have been uploaded; none of them will read a
 * base64 blob out of a description.
 *
 * Which is half of why pictures vanish on the way to a store (VENT2-129).
 * This is the half that belongs in the catalog, and fixing it here fixes
 * every channel at once rather than five times over.
 *
 * The picture becomes a real file in the image library, and the src
 * becomes its URL. It also shrinks the product row: a phone screenshot
 * pasted into a description is a megabyte of base64 in a TEXT column,
 * read on every page that shows the product.
 *
 * IT NEVER THROWS. A description is being SAVED when this runs, and a
 * picture that cannot be written is not a reason to lose the operator's
 * words - the img is left exactly as it was, still stored, still pasteable
 * somewhere else.
 */
final class DescriptionHtml
{
    /** Where a pasted picture lands, beside the rest of the library. */
    private const DIR = 'catalog/_pasted';

    /** What a data: URI may claim to be, matching HtmlSanitizer's own list. */
    private const TYPES = ['png' => 'png', 'jpeg' => 'jpg', 'jpg' => 'jpg', 'gif' => 'gif', 'webp' => 'webp'];

    /**
     * The one way a description enters the catalog: sanitised, and with
     * every pasted picture turned into a file.
     */
    public static function store(?string $html): string
    {
        return self::pastedImagesToFiles(HtmlSanitizer::sanitize($html));
    }

    /**
     * Rewrite every base64 img src to the URL of a real file. Returns the
     * HTML unchanged when there is nothing pasted in it, which is the
     * usual case and costs one regex.
     */
    public static function pastedImagesToFiles(string $html): string
    {
        if (! str_contains($html, 'data:image/')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#(<img\b[^>]*\bsrc=")(data:image/([a-z]+);base64,([A-Za-z0-9+/=\s]+))(")#i',
            function (array $m): string {
                $url = self::write(strtolower($m[3]), $m[4]);

                // Unwritable: leave the paste alone rather than lose it.
                return $url === null ? $m[0] : $m[1] . e($url) . $m[5];
            },
            $html
        );
    }

    /**
     * The pictures a description carries, as they would go to a store.
     * Used to say what a channel will and will not take.
     *
     * @return list<string>
     */
    public static function imageUrls(string $html): array
    {
        if (! preg_match_all('#<img\b[^>]*\bsrc="([^"]+)"#i', $html, $m)) {
            return [];
        }

        return array_values(array_filter(array_map('html_entity_decode', $m[1])));
    }

    /**
     * Write one pasted picture to the library. The file is named by the
     * CONTENT's hash, so pasting the same screenshot into ten products
     * stores it once.
     */
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

        /* It has to BE an image, not merely say it is: the type comes from
           a string the browser wrote and this ends up in the library that
           every push reads from. */
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
