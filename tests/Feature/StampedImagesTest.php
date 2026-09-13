<?php

namespace Tests\Feature;

use App\Models\WatermarkTemplate;
use App\Services\Media\StampedImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A listing's pictures as they go up: the main one marked by default, every
 * one when the listing asks, each marked copy drawn once and reused.
 */
class StampedImagesTest extends TestCase
{
    use RefreshDatabase;

    private function png(string $path, int $w, int $h, bool $alpha = false): string
    {
        $img = imagecreatetruecolor($w, $h);
        if ($alpha) {
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
            imagefilledrectangle($img, 0, 0, (int) ($w / 2), (int) ($h / 2), imagecolorallocatealpha($img, 255, 0, 0, 0));
        } else {
            imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
        }
        ob_start();
        imagepng($img);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function template(): WatermarkTemplate
    {
        return WatermarkTemplate::create([
            'name' => 'Shop logo', 'image_path' => $this->png('catalog/marks/logo.png', 40, 40, true),
            'position' => 'bottom-right', 'size_percent' => 20, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'opacity' => 1,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_by_default_only_the_main_picture_is_marked(): void
    {
        $pictures = [$this->png('catalog/p/main.png', 200, 200), $this->png('catalog/p/second.png', 200, 200)];

        $out = app(StampedImages::class)->paths($pictures, $this->template(), false);

        $this->assertStringStartsWith(StampedImages::DIR . '/', $out[0]);
        $this->assertSame('catalog/p/second.png', $out[1]);
    }

    public function test_every_picture_is_marked_when_the_listing_asks(): void
    {
        $pictures = [$this->png('catalog/p/main.png', 200, 200), $this->png('catalog/p/second.png', 200, 200)];

        $out = app(StampedImages::class)->paths($pictures, $this->template(), true);

        $this->assertStringStartsWith(StampedImages::DIR . '/', $out[0]);
        $this->assertStringStartsWith(StampedImages::DIR . '/', $out[1]);
        $this->assertNotSame($out[0], $out[1]);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($out[1]));
        $this->assertSame([200, 200], [$w, $h], 'nothing is cropped or resized');
    }

    public function test_no_template_sends_the_pictures_as_they_are(): void
    {
        $pictures = [$this->png('catalog/p/main.png', 200, 200)];

        $this->assertSame($pictures, app(StampedImages::class)->paths($pictures, null, true));
    }

    public function test_a_marked_copy_is_drawn_once_and_redrawn_when_the_template_changes(): void
    {
        $pictures = [$this->png('catalog/p/main.png', 200, 200)];
        $template = $this->template();
        $service = app(StampedImages::class);

        $first = $service->paths($pictures, $template, false)[0];
        $this->assertSame($first, $service->paths($pictures, $template, false)[0], 'a second push reuses the marked copy');

        $template->update(['position' => 'top-left']);
        $this->assertNotSame($first, $service->paths($pictures, $template->fresh(), false)[0], 'a changed template draws a new copy');
    }

    public function test_a_picture_that_cannot_be_marked_goes_up_as_it_is(): void
    {
        Storage::disk('public')->put('catalog/p/broken.jpg', 'not an image');
        $template = $this->template();

        $this->assertSame(['catalog/p/broken.jpg'], app(StampedImages::class)->paths(['catalog/p/broken.jpg'], $template, true));
        $this->assertSame(['catalog/p/missing.jpg'], app(StampedImages::class)->paths(['catalog/p/missing.jpg'], $template, true));
    }
}
