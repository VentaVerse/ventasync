<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;

/**
 * THE COMPANY'S OWN LOGO, AT THE SIZE EACH PLACE SHOWS IT.
 *
 * A logo is not a product photograph and cannot be treated as one. The
 * picture cache squares onto white and writes JPEG, which is right for a
 * marketplace and wrong here twice over: a wide lockup would gain white bars,
 * and a transparent PNG would gain a white ground that shows as a slab on the
 * dark sidebar.
 *
 * So a logo is FITTED inside a box rather than squared, keeps its alpha, and
 * is written as PNG. The box is in the filename, beside the picture cache's
 * own copies:
 *
 *   cache/company/mark-216x44fit.png
 *
 * Which means a box that is changed simply asks for a name nothing has
 * written yet. Nothing is ever invalidated.
 *
 * The box is a ceiling, never a stretch. A picture smaller than its box is
 * left alone.
 *
 * ONLY THE HEIGHT IS A SETTING. Fitting keeps the picture's shape, so of the
 * two numbers only the tighter one can bind and the other silently does
 * nothing: two fields would advertise a freedom the arithmetic does not have.
 * The width is not a preference either, it is how much room the column has,
 * which is a fact about the layout rather than a matter of taste.
 */
final class BrandImage
{
    public const NAV = 'nav';
    public const LOGIN = 'login';

    /**
     * The height each place gives the logo on a new install: the sidebar row
     * stands 44px, and the sign-in panel already drew its logo 96px tall.
     *
     * @var array<string, int>
     */
    public const DEFAULTS = [
        self::NAV => 44,
        self::LOGIN => 96,
    ];

    /**
     * The widest a mark may be in each place. Not a setting: the sidebar is a
     * 248px rail with a gutter each side, and on a phone the drawer's close
     * button shares that row, which is what leaves 184. The sign-in panel has
     * the width to itself.
     *
     * @var array<string, int>
     */
    public const WIDTHS = [
        self::NAV => 184,
        self::LOGIN => 420,
    ];

    /** What Settings accepts, so a height cannot be set to nothing or to a wall. */
    public const LIMITS = ['h' => [16, 400]];

    /** @var array<string, array{0: int, 1: int}>|null */
    private static ?array $boxes = null;

    /** Forget the boxes read from Settings, after they are changed. */
    public static function forget(): void
    {
        self::$boxes = null;
    }

    /**
     * The box this place gives the logo: the height as stored, clamped to what
     * is sane, and the width the column simply has.
     *
     * @return array{0: int, 1: int}
     */
    public static function box(string $place): array
    {
        if (self::$boxes === null) {
            self::$boxes = [];
            foreach (self::DEFAULTS as $key => $height) {
                self::$boxes[$key] = [
                    self::WIDTHS[$key],
                    self::clamp((int) self::setting('logo_' . $key . '_h', $height), self::LIMITS['h']),
                ];
            }
        }

        return self::$boxes[$place] ?? [self::WIDTHS[self::NAV], self::DEFAULTS[self::NAV]];
    }

    /** The height this place gives the logo, which is the one thing Settings holds. */
    public static function height(string $place): int
    {
        return self::box($place)[1];
    }

    /**
     * The logo's URL for this place: a fitted copy where one can be made, the
     * uploaded file where it cannot, and the shipped mark when nothing has
     * been uploaded at all.
     */
    public static function url(?string $uploaded, string $place): string
    {
        return self::show($uploaded, $place)['url'];
    }

    /**
     * THE LOGO AND THE SIZE IT DRAWS AT.
     *
     * A picture has two sizes: the one the file is, and the one the page gives
     * it. When the page fixes its own, the box here decides nothing and a
     * logo that was resized looks unchanged. So the size travels with the URL
     * and the page puts it on the tag.
     *
     * It is the FITTED FILE'S OWN size, not the box: a wide lockup lands
     * shorter than its box, and handing the page the box would stretch back
     * exactly what the fitting avoided.
     *
     * @return array{url: string, width: int, height: int}
     */
    public static function show(?string $uploaded, string $place): array
    {
        $uploaded = trim((string) $uploaded);

        /* THE BOX GOVERNS WHICHEVER LOGO IS SHOWN. Nothing uploaded means the
           shipped mark, and it is fitted like any other: a box that only
           applied to an upload would look broken to anyone who has not made
           one. */
        if ($uploaded === '') {
            $shipped = self::shipped($place);

            return $shipped !== null
                ? self::measured(asset('storage/' . $shipped), Storage::disk('public')->path($shipped), $place)
                : self::measured(asset('images/brand/ventasync.png'), public_path('images/brand/ventasync.png'), $place);
        }

        $fitted = self::fitted($uploaded, $place);
        $path = $fitted ?? ltrim($uploaded, '/');

        return self::measured(asset('storage/' . $path), Storage::disk('public')->path($path), $place);
    }

    /**
     * The picture's own size, or the box when it cannot be measured: an SVG
     * carries no pixels, and a file that is not a picture carries nothing.
     *
     * @return array{url: string, width: int, height: int}
     */
    private static function measured(string $url, string $absolute, string $place): array
    {
        $size = is_file($absolute) ? @getimagesize($absolute) : false;
        if ($size === false || empty($size[0]) || empty($size[1])) {
            [$w, $h] = self::box($place);

            return ['url' => $url, 'width' => $w, 'height' => $h];
        }

        return ['url' => $url, 'width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    /**
     * The shipped mark, fitted to this place's box. It lives with the code
     * rather than on the public disk, so it is read from there and its copy
     * is written beside every other, under its own folder.
     */
    public static function shipped(string $place): ?string
    {
        $source = public_path('images/brand/ventasync.png');
        if (! is_file($source)) {
            return null;
        }

        [$w, $h] = self::box($place);
        $target = ImageCache::DIR . '/brand/ventasync-' . $w . 'x' . $h . 'fit.png';

        $disk = Storage::disk('public');
        if ($disk->exists($target) && $disk->lastModified($target) >= filemtime($source)) {
            return $target;
        }

        $bytes = self::fitPng($source, $w, $h);
        if ($bytes === null) {
            return null;
        }
        $disk->put($target, $bytes);

        return $target;
    }

    /**
     * The fitted copy's path on the public disk, made now when it is missing
     * or older than the logo. Null when it cannot be made, which is never a
     * reason to show nothing.
     */
    public static function fitted(string $path, string $place): ?string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, ImageCache::DIR . '/')) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }

        [$w, $h] = self::box($place);
        $info = pathinfo($path);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';
        $target = ImageCache::DIR . '/' . $dir . $info['filename'] . '-' . $w . 'x' . $h . 'fit.png';

        if (ImageCache::fresh($target, $path)) {
            return $target;
        }

        $bytes = self::fitPng($disk->path($path), $w, $h);
        if ($bytes === null) {
            return null;
        }
        $disk->put($target, $bytes);

        return $target;
    }

    /**
     * The picture fitted inside the box, its shape kept and its transparency
     * with it, as PNG. A picture already inside the box is not enlarged.
     */
    private static function fitPng(string $absolute, int $boxW, int $boxH): ?string
    {
        $bytes = @file_get_contents($absolute);
        if ($bytes === false) {
            return null;
        }
        /* An SVG is already any size it is asked to be, and GD cannot read
           one: it is served as it is. */
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min($boxW / $width, $boxH / $height, 1);
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($w, $h);
        // Transparency survives: no white ground is painted under the mark.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        $ok = imagepng($canvas, null, 8);

        return $ok ? (string) ob_get_clean() : (ob_end_clean() ? null : null);
    }

    private static function clamp(int $value, array $range): int
    {
        return max($range[0], min($range[1], $value));
    }

    private static function setting(string $column, int $fallback): int
    {
        try {
            $value = \App\Models\Setting::query()->value($column);

            return $value === null ? $fallback : (int) $value;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
