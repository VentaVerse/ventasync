<?php

namespace Tests\Feature;

use App\Services\Media\StoreReadyImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreReadyImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function heavyPhoto(string $path, int $side = 1400): void
    {
        $image = imagecreatetruecolor($side, $side);
        mt_srand(7);
        for ($x = 0; $x < $side; $x++) {
            for ($y = 0; $y < $side; $y++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagejpeg($image, null, 100);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        imagedestroy($image);
    }

    private function smallPicture(string $path, string $kind): void
    {
        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 30));
        ob_start();
        $kind === 'webp' ? imagewebp($image, null, 80) : imagejpeg($image, null, 85);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        imagedestroy($image);
    }

    public function test_a_picture_over_lazadas_size_goes_up_as_a_fitted_jpeg_and_the_original_stays(): void
    {
        $this->heavyPhoto('catalog/heavy.jpg');
        $disk = Storage::disk('public');
        $originalBytes = $disk->size('catalog/heavy.jpg');
        $this->assertGreaterThan(1000000, $originalBytes, 'the fixture must break the rule it tests');

        $fitted = StoreReadyImages::fit('catalog/heavy.jpg', StoreReadyImages::LAZADA);

        $this->assertNotNull($fitted);
        $this->assertSame('cache/catalog/heavy-fit2000.jpg', $fitted);
        $this->assertLessThanOrEqual(1000000, $disk->size($fitted));
        $info = getimagesize($disk->path($fitted));
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertLessThanOrEqual(2000, max($info[0], $info[1]));
        $this->assertSame($originalBytes, $disk->size('catalog/heavy.jpg'), 'the original is never changed');
    }

    public function test_a_webp_is_converted_even_when_it_is_small(): void
    {
        $this->smallPicture('catalog/shot.webp', 'webp');

        $fitted = StoreReadyImages::fit('catalog/shot.webp', StoreReadyImages::LAZADA);

        $this->assertNotNull($fitted);
        $this->assertSame(IMAGETYPE_JPEG, getimagesize(Storage::disk('public')->path($fitted))[2]);
    }

    public function test_a_picture_the_store_accepts_goes_up_untouched(): void
    {
        $this->smallPicture('catalog/fine.jpg', 'jpg');

        $this->assertSame('catalog/fine.jpg', StoreReadyImages::fit('catalog/fine.jpg', StoreReadyImages::LAZADA));
        $this->assertSame(['catalog/fine.jpg'], StoreReadyImages::paths(['catalog/fine.jpg'], StoreReadyImages::SHOPEE));
        $this->assertFalse(Storage::disk('public')->exists(\App\Services\Media\ImageCache::DIR), 'nothing is written for a picture that fits');
    }

    public function test_a_copy_is_made_once_and_reused(): void
    {
        $this->smallPicture('catalog/shot.webp', 'webp');
        $first = StoreReadyImages::fit('catalog/shot.webp', StoreReadyImages::LAZADA);
        $written = Storage::disk('public')->lastModified($first);

        $this->assertSame($first, StoreReadyImages::fit('catalog/shot.webp', StoreReadyImages::LAZADA));
        $this->assertSame($written, Storage::disk('public')->lastModified($first));
    }

    public function test_an_upload_given_a_full_path_gets_the_fitted_file(): void
    {
        $this->heavyPhoto('catalog/heavy.jpg');
        $disk = Storage::disk('public');
        $original = $disk->path('catalog/heavy.jpg');

        $fitted = StoreReadyImages::fitLocalFile($original, StoreReadyImages::LAZADA);

        $this->assertNotSame($original, $fitted);
        $this->assertFileExists($fitted);
        $this->assertLessThanOrEqual(1000000, filesize($fitted));
        $this->assertSame('/tmp/elsewhere.jpg', StoreReadyImages::fitLocalFile('/tmp/elsewhere.jpg', StoreReadyImages::LAZADA), 'a file off the public disk is left alone');
    }

    public function test_a_picture_that_cannot_be_read_goes_up_as_it_is(): void
    {
        Storage::disk('public')->put('catalog/broken.jpg', 'not a picture');

        $this->assertNull(StoreReadyImages::fit('catalog/broken.jpg', StoreReadyImages::LAZADA));
        $this->assertSame(['catalog/broken.jpg'], StoreReadyImages::paths(['catalog/broken.jpg'], StoreReadyImages::LAZADA));
    }
}
