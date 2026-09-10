<?php

namespace Tests\Feature;

use App\Services\Media\Watermarker;
use Tests\TestCase;

/**
 * THE STAMPER (VENT2-128, W1).
 *
 * The rules the owner settled on 2026-09-07, each with a test: nothing is
 * cropped, nothing is shrunk, the transparent parts of the mark let the
 * photograph through, and a mark that cannot be used answers null rather
 * than failing a push and leaving the product unlisted.
 */
class WatermarkerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/wm-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** A plain photograph, one flat colour. */
    private function photo(int $w = 400, int $h = 400, array $rgb = [10, 120, 200]): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
        $path = $this->dir . '/photo-' . $w . 'x' . $h . '.jpg';
        imagejpeg($im, $path, 95);
        imagedestroy($im);

        return $path;
    }

    /**
     * A mark whose LEFT half is opaque red and right half fully
     * transparent, so a test can prove the transparent side let the
     * photograph through.
     */
    private function mark(int $w = 100, int $h = 100): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $red = imagecolorallocatealpha($im, 255, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, (int) ($w / 2) - 1, $h - 1, $red);
        $path = $this->dir . '/mark.png';
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    private function pixel(string $bytes, int $x, int $y): array
    {
        $im = imagecreatefromstring($bytes);
        $rgba = imagecolorat($im, $x, $y);
        imagedestroy($im);

        return [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
    }

    public function test_the_picture_keeps_its_exact_dimensions(): void
    {
        $bytes = (new Watermarker())->stamp($this->photo(1200, 800), $this->mark());

        $this->assertNotNull($bytes);
        [$w, $h] = getimagesizefromstring($bytes);
        $this->assertSame(1200, $w, 'Nothing is resized: a marketplace minimum resolution must survive the mark.');
        $this->assertSame(800, $h);
    }

    public function test_the_transparent_half_of_the_mark_lets_the_photograph_through(): void
    {
        $wm = new Watermarker();
        // Full frame, no margin: the mark's left half covers the picture's left half.
        $bytes = $wm->stamp($this->photo(400, 400), $this->mark(), 'middle-center', 100.0, 1.0, 0.0);
        $this->assertNotNull($bytes);

        [$r] = $this->pixel($bytes, 40, 200);
        $this->assertGreaterThan(200, $r, 'The opaque half of the mark did not cover the photograph.');

        [$r2, $g2, $b2] = $this->pixel($bytes, 360, 200);
        $this->assertLessThan(60, $r2, 'The transparent half covered the photograph instead of letting it through.');
        $this->assertGreaterThan(80, $b2, 'The photograph should still be showing here.');
    }

    public function test_the_mark_lands_where_it_was_told(): void
    {
        $wm = new Watermarker();
        $photo = $this->photo(400, 400);

        $topLeft = $wm->stamp($photo, $this->mark(), 'top-left', 25.0, 1.0, 0.0);
        [$r] = $this->pixel($topLeft, 5, 5);
        $this->assertGreaterThan(200, $r, 'A top-left mark is not in the top left.');
        [$r2] = $this->pixel($topLeft, 395, 395);
        $this->assertLessThan(60, $r2, 'A top-left mark reached the bottom right.');

        $bottomRight = $wm->stamp($photo, $this->mark(), 'bottom-right', 25.0, 1.0, 0.0);
        [$r3] = $this->pixel($bottomRight, 5, 5);
        $this->assertLessThan(60, $r3, 'A bottom-right mark reached the top left.');
    }

    /** Opacity multiplies the mark's own alpha rather than replacing it. */
    public function test_opacity_softens_the_mark(): void
    {
        $wm = new Watermarker();
        $photo = $this->photo(400, 400);

        [$full] = $this->pixel($wm->stamp($photo, $this->mark(), 'middle-center', 100.0, 1.0, 0.0), 40, 200);
        [$half] = $this->pixel($wm->stamp($photo, $this->mark(), 'middle-center', 100.0, 0.4, 0.0), 40, 200);

        $this->assertGreaterThan($half, $full, 'A softer mark should let more of the photograph through.');
        $this->assertGreaterThan(30, $half, 'A softened mark still has to be there.');
    }

    public function test_a_mark_larger_than_the_picture_is_refused_rather_than_cropped(): void
    {
        $wm = new Watermarker();
        // 400px photo, a mark asked to be 100% of a 400px edge but 2000px tall.
        $tall = $this->mark(100, 2000);

        $this->assertNull($wm->stamp($this->photo(400, 400), $tall, 'middle-center', 100.0),
            'A mark the photograph cannot hold is a mistake to report, not something to crop silently.');
    }

    public function test_an_unreadable_mark_answers_null_and_never_throws(): void
    {
        $wm = new Watermarker();
        $notAnImage = $this->dir . '/notes.txt';
        file_put_contents($notAnImage, 'hello');

        $this->assertNull($wm->stamp($this->photo(), $notAnImage));
        $this->assertNull($wm->stamp($this->photo(), $this->dir . '/missing.png'));
        $this->assertNull($wm->stamp($this->dir . '/missing.jpg', $this->mark()));
    }

    /** The mark keeps its proportions: a ribbon must not come out square. */
    public function test_a_wide_mark_is_not_squashed(): void
    {
        $wm = new Watermarker();
        $wide = $this->mark(200, 50);

        $bytes = $wm->stamp($this->photo(400, 400), $wide, 'top-left', 50.0, 1.0, 0.0);
        $this->assertNotNull($bytes);

        // 50% of 400 = 200 wide, so a 200x50 mark is 50 tall: row 60 is clear.
        [$r] = $this->pixel($bytes, 5, 5);
        $this->assertGreaterThan(200, $r);
        [$r2] = $this->pixel($bytes, 5, 60);
        $this->assertLessThan(60, $r2, 'The mark was stretched to fill a square it does not belong in.');
    }
}
