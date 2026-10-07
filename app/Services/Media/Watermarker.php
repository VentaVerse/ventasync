<?php

namespace App\Services\Media;

class Watermarker
{
    public const POSITIONS = [
        'top-left', 'top-center', 'top-right',
        'middle-left', 'middle-center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    private const JPEG_QUALITY = 90;

    public function stamp(
        string $imagePath,
        string $markPath,
        string $position = 'bottom-right',
        float $sizePercent = 18.0,
        float $opacity = 1.0,
        float $offsetXPercent = 0.0,
        float $offsetYPercent = 0.0,
    ): ?string {
        $photo = $this->open($imagePath);
        $mark = $this->open($markPath);
        if ($photo === null || $mark === null) {
            return null;
        }

        try {
            $pw = imagesx($photo);
            $ph = imagesy($photo);
            $shorter = min($pw, $ph);

            $sizePercent = max(1.0, min(100.0, $sizePercent));
            $opacity = max(0.0, min(1.0, $opacity));
            $offsetXPercent = max(-50.0, min(50.0, $offsetXPercent));
            $offsetYPercent = max(-50.0, min(50.0, $offsetYPercent));

            $targetW = (int) round($shorter * $sizePercent / 100);
            $targetH = (int) round(imagesy($mark) * ($targetW / max(1, imagesx($mark))));
            if ($targetW < 1 || $targetH < 1) {
                return null;
            }

            if ($targetW > $pw || $targetH > $ph) {
                return null;
            }

            $scaled = imagecreatetruecolor($targetW, $targetH);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
            imagecopyresampled($scaled, $mark, 0, 0, 0, 0, $targetW, $targetH, imagesx($mark), imagesy($mark));

            if ($opacity < 1.0) {
                $this->fade($scaled, $opacity);
            }

            [$x, $y] = $this->place(
                $position, $pw, $ph, $targetW, $targetH,
                (int) round($shorter * $offsetXPercent / 100),
                (int) round($shorter * $offsetYPercent / 100),
            );

            imagealphablending($photo, true);
            imagecopy($photo, $scaled, $x, $y, 0, 0, $targetW, $targetH);

            ob_start();
            imagejpeg($photo, null, self::JPEG_QUALITY);
            $bytes = ob_get_clean();

            return $bytes !== '' ? $bytes : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function place(string $position, int $pw, int $ph, int $mw, int $mh, int $dx, int $dy): array
    {
        $position = in_array($position, self::POSITIONS, true) ? $position : 'bottom-right';
        [$vertical, $horizontal] = explode('-', $position);

        $x = match ($horizontal) {
            'left' => 0,
            'center' => (int) round(($pw - $mw) / 2),
            default => $pw - $mw,
        } + $dx;
        $y = match ($vertical) {
            'top' => 0,
            'middle' => (int) round(($ph - $mh) / 2),
            default => $ph - $mh,
        } + $dy;

        return [max(0, min($x, $pw - $mw)), max(0, min($y, $ph - $mh))];
    }

    private function fade(\GdImage $mark, float $opacity): void
    {
        $w = imagesx($mark);
        $h = imagesy($mark);
        imagealphablending($mark, false);
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $rgba = imagecolorat($mark, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $faded = (int) round(127 - ((127 - $alpha) * $opacity));
                imagesetpixel($mark, $x, $y, imagecolorallocatealpha(
                    $mark,
                    ($rgba >> 16) & 0xFF,
                    ($rgba >> 8) & 0xFF,
                    $rgba & 0xFF,
                    min(127, max(0, $faded))
                ));
            }
        }
    }

    private function open(string $path): ?\GdImage
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => null,
        };
        if (! $image) {
            return null;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }
}
