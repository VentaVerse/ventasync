<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Media\ImageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        ImageCache::forget();
    }

    protected function tearDown(): void
    {
        ImageCache::forget();
        parent::tearDown();
    }

    private function picture(string $path, int $w, int $h, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($w, $h);
        if ($transparent) {
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagefilledrectangle($image, (int) ($w / 4), (int) ($h / 4), (int) ($w * 3 / 4), (int) ($h * 3 / 4), imagecolorallocate($image, 20, 90, 200));
        } else {
            imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 30));
        }
        ob_start();
        imagepng($image);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        imagedestroy($image);

        return $path;
    }

    private function noisyPhoto(string $path, int $side): string
    {
        $image = imagecreatetruecolor($side, $side);
        mt_srand(11);
        for ($x = 0; $x < $side; $x++) {
            for ($y = 0; $y < $side; $y++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagejpeg($image, null, 100);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        imagedestroy($image);

        return $path;
    }

    public function test_a_thumbnail_is_a_square_jpeg_on_white_beside_the_library(): void
    {
        $this->picture('catalog/Guitars/wide shot.png', 800, 400);

        $copy = ImageCache::path('catalog/Guitars/wide shot.png', ImageCache::THUMB);

        $this->assertSame('cache/catalog/Guitars/wide shot-200x200.jpg', $copy);
        $disk = Storage::disk('public');
        [$w, $h, $type] = getimagesize($disk->path($copy));
        $this->assertSame([200, 200, IMAGETYPE_JPEG], [$w, $h, $type]);
        $image = imagecreatefromjpeg($disk->path($copy));
        $top = imagecolorsforindex($image, imagecolorat($image, 100, 5));
        $this->assertGreaterThan(245, min($top['red'], $top['green'], $top['blue']), 'the band above a wide picture is white, nothing is cropped');
        $this->assertTrue($disk->exists('catalog/Guitars/wide shot.png'), 'the original stays');
        $this->assertStringContainsString('/storage/cache/catalog/Guitars/wide%20shot-200x200.jpg', (string) ImageCache::url('catalog/Guitars/wide shot.png'));
    }

    public function test_a_transparent_picture_lands_on_white(): void
    {
        $copy = ImageCache::path($this->picture('catalog/clear.png', 300, 300, true), ImageCache::PREVIEW);

        $image = imagecreatefromjpeg(Storage::disk('public')->path($copy));
        $corner = imagecolorsforindex($image, imagecolorat($image, 2, 2));
        $this->assertGreaterThan(245, min($corner['red'], $corner['green'], $corner['blue']));
    }

    public function test_a_copy_is_made_once_and_drawn_again_when_the_picture_changes(): void
    {
        $this->picture('catalog/p.png', 300, 300);
        $disk = Storage::disk('public');
        $copy = ImageCache::path('catalog/p.png');
        touch($disk->path($copy), time() - 100);
        touch($disk->path('catalog/p.png'), time() - 200);
        $made = $disk->lastModified($copy);

        $this->assertSame($copy, ImageCache::path('catalog/p.png'));
        $this->assertSame($made, $disk->lastModified($copy), 'an up to date copy is reused');

        touch($disk->path('catalog/p.png'), time());
        ImageCache::path('catalog/p.png');
        $this->assertGreaterThan($made, $disk->lastModified($copy), 'a changed picture draws its copy again');
    }

    public function test_a_push_copy_is_under_a_megabyte_whatever_the_photo(): void
    {
        $this->noisyPhoto('catalog/noisy.jpg', 1600);
        $disk = Storage::disk('public');

        $copy = ImageCache::path('catalog/noisy.jpg', ImageCache::PUSH);

        $this->assertNotNull($copy);
        $this->assertStringStartsWith('cache/catalog/noisy-', $copy);
        $this->assertLessThanOrEqual(ImageCache::PUSH_MAX_BYTES, $disk->size($copy));
        [$w, $h, $type] = getimagesize($disk->path($copy));
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame($w, $h);
        $this->assertGreaterThanOrEqual(300, $w, 'never below the stores\' floor');
    }

    public function test_the_sizes_come_from_settings_within_their_limits(): void
    {
        $this->assertSame(ImageCache::DEFAULTS, [
            ImageCache::THUMB => ImageCache::side(ImageCache::THUMB),
            ImageCache::PREVIEW => ImageCache::side(ImageCache::PREVIEW),
            ImageCache::PUSH => ImageCache::side(ImageCache::PUSH),
        ], 'a new install already has sizes every store accepts');

        Setting::singleton()->forceFill(['image_thumb_size' => 120, 'image_push_size' => 5000])->save();
        ImageCache::forget();

        $this->assertSame(120, ImageCache::side(ImageCache::THUMB));
        $this->assertSame(ImageCache::DEFAULTS[ImageCache::PUSH], ImageCache::side(ImageCache::PUSH), 'a size outside the limits falls back to the default');
        $this->picture('catalog/q.png', 300, 300);
        $this->assertSame('cache/catalog/q-120x120.jpg', ImageCache::path('catalog/q.png'));
    }

    public function test_nothing_is_made_for_a_missing_or_unreadable_picture_or_a_path_that_climbs(): void
    {
        Storage::disk('public')->put('catalog/broken.jpg', 'not a picture');

        $this->assertNull(ImageCache::path('catalog/broken.jpg'));
        $this->assertNull(ImageCache::path('catalog/missing.jpg'));
        $this->assertNull(ImageCache::path('catalog/../../.env'));
        $this->assertNull(ImageCache::url(''));
        $this->assertStringEndsWith('/storage/catalog/broken.jpg', (string) ImageCache::url('catalog/broken.jpg'), 'an unreadable picture keeps its own address, so the page removes it');
        $this->assertSame(['catalog/broken.jpg'], ImageCache::pushPaths(['catalog/broken.jpg']));
    }

    public function test_lazada_is_handed_the_push_copy(): void
    {
        $this->noisyPhoto('catalog/heavy.jpg', 1400);
        $pid = (int) DB::table((string) config('catalog.prefix') . 'product')->insertGetId([
            'model' => 'IC-1', 'sku' => 'IC-1', 'quantity' => 1, 'price' => 100, 'status' => 1,
            'image' => 'catalog/heavy.jpg', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);

        $urls = app(\Extensions\lazada\Services\Lazada\LazadaImages::class)->getProductImageUrls($pid, null);

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('/storage/cache/catalog/heavy-1000x1000.jpg', $urls[0]);
    }
}
