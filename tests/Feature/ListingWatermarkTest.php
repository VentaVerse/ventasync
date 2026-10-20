<?php

namespace Tests\Feature;

use App\Models\WatermarkTemplate;
use App\Services\Media\ListingWatermark;
use App\Services\Media\StampedImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListingWatermarkTest extends TestCase
{
    use RefreshDatabase;

    private function png(string $path, bool $alpha = false): string
    {
        $img = imagecreatetruecolor($alpha ? 40 : 200, $alpha ? 40 : 200);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, $alpha ? imagecolorallocatealpha($img, 0, 0, 0, 127) : imagecolorallocate($img, 20, 90, 160));
        if ($alpha) {
            imagefilledrectangle($img, 0, 0, 20, 20, imagecolorallocatealpha($img, 255, 255, 255, 0));
        }
        ob_start();
        imagepng($img);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function template(string $name): WatermarkTemplate
    {
        return WatermarkTemplate::create([
            'channel' => 'shopee', 'store_id' => 1,
            'name' => $name, 'image_path' => $this->png('catalog/marks/' . $name . '.png', true),
            'position' => 'bottom-right', 'size_percent' => 20, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'opacity' => 1,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_only_the_listings_own_template_marks_its_pictures(): void
    {
        $own = $this->template('own');
        $pictures = [$this->png('catalog/p/a.png'), $this->png('catalog/p/b.png')];

        $mine = ListingWatermark::paths($pictures, (object) ['watermark_template_id' => $own->id, 'watermark_all_images' => false], 'shopee', 1);
        $none = ListingWatermark::paths($pictures, (object) ['watermark_template_id' => null, 'watermark_all_images' => false], 'shopee', 1);

        $this->assertStringStartsWith(StampedImages::DIR . '/', $mine[0]);
        $this->assertSame('catalog/p/b.png', $mine[1], 'unticked: the main picture only');
        $this->assertSame($pictures, $none, 'a listing with no template of its own is not marked, whatever its group');
    }

    public function test_the_tick_marks_every_picture_and_no_template_marks_none(): void
    {
        $own = $this->template('own');
        $pictures = [$this->png('catalog/p/a.png'), $this->png('catalog/p/b.png')];

        $all = ListingWatermark::paths($pictures, (object) ['watermark_template_id' => $own->id, 'watermark_all_images' => true], 'shopee', 1);
        $this->assertStringStartsWith(StampedImages::DIR . '/', $all[1]);

        $this->assertSame($pictures, ListingWatermark::paths($pictures, null, 'shopee', 1));
    }
}
