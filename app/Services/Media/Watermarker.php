<?php

namespace App\Services\Media;

/**
 * A MARK LAID OVER A PRODUCT PHOTO, ON THE WAY OUT.
 *
 * the owner settled the mechanics on 2026-09-07: it is an OVERLAY, not a
 * frame. The mark is a PNG with an alpha channel composited on top of the
 * picture at its original dimensions - where the PNG is transparent the
 * photograph shows through, where it is opaque it covers. A 2000x2000
 * image stays 2000x2000, so no marketplace's dimension or minimum
 * resolution rule is touched, and nothing of the product is cropped away
 * to make room.
 *
 * Two shapes, one mechanism, because both are a PNG laid over a picture:
 *
 *   a LOGO      small, placed at one of nine positions, scaled to a percent
 *               of the picture's SHORTER edge so it looks the same size on
 *               a square photo and a tall one, with a margin off the edge.
 *   a TEMPLATE  the size of the whole frame, mostly transparent, carrying
 *               whatever the seller authored - a border, a corner ribbon, a
 *               badge. The transparent middle lets the photo through, so a
 *               "border" costs no pixels of the product.
 *
 * The difference is only the size percent: 100 covers the frame.
 *
 * GD, because that is what the server has - no Imagick, no Intervention.
 * TikTokProductPush::squareImage is the working precedent for this exact
 * kind of work and this follows its shape, with alpha blending kept on so
 * the mark's transparency survives the copy.
 *
 * IT ANSWERS null RATHER THAN THROWING. This runs while a push is being
 * assembled: a mark that cannot be read is a reason to send the photograph
 * as it is, never a reason to fail the push and leave the product
 * unlisted.
 */
class Watermarker
{
    /** The nine places a mark can sit. */
    public const POSITIONS = [
        'top-left', 'top-center', 'top-right',
        'middle-left', 'middle-center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    /**
     * The quality the re-encode uses. 90 is what squareImage already
     * sends, and it keeps a stamped photo inside every channel's size
     * ceiling.
     */
    private const JPEG_QUALITY = 90;

    /**
     * Stamp one picture.
     *
     * @param  string  $imagePath  the photograph, an absolute path
     * @param  string  $markPath   the mark, an absolute path to a PNG
     * @param  string  $position   one of POSITIONS
     * @param  float  $sizePercent  the mark's width as a percent of the shorter edge; 100 covers the frame
     * @param  float  $opacity     0 to 1, multiplied into the mark's own alpha
     * @param  float  $offsetXPercent  a nudge from the anchored position, as a percent of the
     *                                 shorter edge: positive moves right, negative left
     * @param  float  $offsetYPercent  the same, down for positive and up for negative
     * @return string|null  the stamped bytes, or null when it could not be done
     */
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
            $photo && imagedestroy($photo);
            $mark && imagedestroy($mark);

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

            /* The mark keeps its own proportions: a wide ribbon scaled to
               a square would be squashed, and a seller who authored a
               border would get a distorted one. */
            $targetW = (int) round($shorter * $sizePercent / 100);
            $targetH = (int) round(imagesy($mark) * ($targetW / max(1, imagesx($mark))));
            if ($targetW < 1 || $targetH < 1) {
                return null;
            }

            /* A mark bigger than the picture is a mistake worth refusing
               rather than cropping silently: the seller asked for
               something the photograph cannot hold. */
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

            // Blending ON for the paste, or the mark's transparent pixels
            // would be copied as transparency and punch a hole in the photo.
            imagealphablending($photo, true);
            imagecopy($photo, $scaled, $x, $y, 0, 0, $targetW, $targetH);
            imagedestroy($scaled);

            ob_start();
            imagejpeg($photo, null, self::JPEG_QUALITY);
            $bytes = ob_get_clean();

            return $bytes !== '' ? $bytes : null;
        } catch (\Throwable) {
            return null;
        } finally {
            imagedestroy($photo);
            imagedestroy($mark);
        }
    }

    /**
     * Where the mark sits, as a top-left corner: anchored to one of the
     * nine places, then nudged by the offset.
     *
     * The offset is in the picture's own directions, the way every image
     * editor counts them - right and down are positive - so the same
     * number means the same move whichever corner the mark is anchored to.
     * Zero is flush against the anchor (the owner, 2026-09-11: "default is 0
     * and can go to negative and positive").
     *
     * A nudge past the edge stops AT the edge. The mark never leaves the
     * photograph, so dragging a slider too far pins it rather than losing
     * it off the frame.
     */
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

    /**
     * Soften the mark without editing the PNG: the multiplier applies to
     * each pixel's OWN alpha, so a logo that is already half transparent
     * at 50% opacity becomes a quarter, not a half.
     */
    private function fade(\GdImage $mark, float $opacity): void
    {
        $w = imagesx($mark);
        $h = imagesy($mark);
        imagealphablending($mark, false);
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $rgba = imagecolorat($mark, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                // 0 is opaque and 127 is invisible, so less opacity is MORE.
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

    /** Read an image of any type this server can, alpha preserved. */
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
