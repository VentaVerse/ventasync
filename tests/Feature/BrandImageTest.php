<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Media\BrandImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * THE COMPANY'S LOGO, AT THE SIZE EACH PLACE SHOWS IT.
 *
 * A logo is not a product photograph. The picture cache squares onto white
 * and writes JPEG, which would give a wide lockup white bars and a
 * transparent mark a white slab on the dark sidebar. So a logo is fitted
 * inside a box, keeps its alpha, and is written as PNG.
 */
class BrandImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        BrandImage::forget();
    }

    /** A PNG of the given size, transparent at its corner. */
    private function logo(string $path, int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 2, 2, $w - 3, $h - 3, imagecolorallocate($im, 10, 20, 30));
        ob_start();
        imagepng($im);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function sizeOf(string $path): array
    {
        return array_slice((array) getimagesize(Storage::disk('public')->path($path)), 0, 2);
    }

    /** A WIDE LOCKUP MEETS THE WIDTH FIRST, and is never stretched to the box. */
    public function test_a_wide_lockup_is_fitted_by_its_width(): void
    {
        $this->logo('company/wide.png', 1200, 200);

        $fitted = BrandImage::fitted('company/wide.png', BrandImage::NAV);

        $this->assertSame('cache/company/wide-184x44fit.png', $fitted, 'the box is in the name, in the one flat cache');
        /* 1200x200 into 184x44: the width is the tighter limit, so it lands
           at 184 wide and 31 tall, short of the box's height and never
           stretched to fill it. */
        $this->assertSame([184, 31], $this->sizeOf($fitted));
    }

    /** A SQUARE MARK MEETS THE HEIGHT FIRST. */
    public function test_a_square_mark_is_fitted_by_its_height(): void
    {
        $this->logo('company/mark.png', 500, 500);

        $fitted = BrandImage::fitted('company/mark.png', BrandImage::NAV);

        $this->assertSame([44, 44], $this->sizeOf($fitted), 'a square mark lands at the box height, square');
    }

    /** TRANSPARENCY SURVIVES. A white ground would read as a slab on the dark sidebar. */
    public function test_the_mark_keeps_its_transparency(): void
    {
        $this->logo('company/alpha.png', 400, 100);

        $fitted = BrandImage::fitted('company/alpha.png', BrandImage::LOGIN);
        $im = imagecreatefrompng(Storage::disk('public')->path($fitted));
        $corner = imagecolorsforindex($im, imagecolorat($im, 0, 0));

        $this->assertSame(127, $corner['alpha'], 'the corner is still fully transparent');
    }

    /** A picture already inside its box is not enlarged. */
    public function test_a_small_logo_is_left_at_its_own_size(): void
    {
        $this->logo('company/small.png', 90, 20);

        $this->assertSame([90, 20], $this->sizeOf(BrandImage::fitted('company/small.png', BrandImage::NAV)));
    }

    /** The two places keep their own box, and a height that changes renames the file. */
    public function test_each_place_keeps_its_own_box(): void
    {
        $this->logo('company/two.png', 1200, 200);

        $nav = BrandImage::fitted('company/two.png', BrandImage::NAV);
        $login = BrandImage::fitted('company/two.png', BrandImage::LOGIN);
        $this->assertNotSame($nav, $login, 'the two places have their own room and their own copy');

        /* Settings is a single row, and the height is read from the first one,
           so the row is changed rather than a second one added. */
        $row = Setting::query()->first() ?? Setting::query()->create([]);
        $row->forceFill(['logo_nav_h' => 72])->save();
        BrandImage::forget();

        $this->assertSame('cache/company/two-184x72fit.png',
            BrandImage::fitted('company/two.png', BrandImage::NAV),
            'the box names the file, so a new height asks for a name nothing has written yet');
        $this->assertSame($login, BrandImage::fitted('company/two.png', BrandImage::LOGIN),
            'and the other place is untouched by it');
    }

    /**
     * ONLY THE HEIGHT IS A SETTING.
     *
     * Fitting keeps the picture's shape, so of the two numbers only the
     * tighter can bind and the other silently does nothing. A width field
     * would advertise a freedom the arithmetic does not have, and the width a
     * mark may use is the room its column has, not a matter of taste.
     */
    public function test_the_width_is_the_columns_own_and_never_a_field(): void
    {
        $this->assertArrayNotHasKey('w', BrandImage::LIMITS, 'no width is offered for typing');

        foreach ([BrandImage::NAV, BrandImage::LOGIN] as $place) {
            [$w, $h] = BrandImage::box($place);
            $this->assertSame(BrandImage::WIDTHS[$place], $w, 'the width is the column, whatever is stored');
            $this->assertSame(BrandImage::height($place), $h);
        }

        $tab = file_get_contents(resource_path('views/settings/partials/tab-website.blade.php'));
        $this->assertNotFalse($tab);
        /* The field names are built per place, so the guard reads what the
           view actually writes rather than a name it never spells out. */
        $this->assertStringContainsString('logo_{{ $place }}_h', $tab);
        $this->assertStringNotContainsString('logo_{{ $place }}_w', $tab, 'the page offers one field per place');
        $this->assertStringNotContainsString('logo_nav_w', $tab);
        $this->assertStringNotContainsString('logo_login_w', $tab);
    }

    /** Nothing uploaded means the shipped mark, and an unreadable file is shown as it is. */
    public function test_it_never_shows_nothing(): void
    {
        $this->assertStringContainsString('ventasync', BrandImage::url(null, BrandImage::NAV));
        $this->assertStringContainsString('ventasync', BrandImage::url('  ', BrandImage::NAV));

        Storage::disk('public')->put('company/notapicture.png', 'this is not a picture');
        $this->assertStringContainsString('company/notapicture.png',
            BrandImage::url('company/notapicture.png', BrandImage::NAV),
            'a file GD cannot read is served as it is rather than not at all');

        $this->assertNull(BrandImage::fitted('company/../../etc/passwd', BrandImage::NAV));
    }

    /**
     * THE BOX GOVERNS THE SHIPPED MARK TOO.
     *
     * Most installs never upload a logo. A box that only applied to an upload
     * would look broken to all of them: the size is changed and nothing moves.
     */
    public function test_the_shipped_mark_is_fitted_like_any_other(): void
    {
        $fitted = BrandImage::shipped(BrandImage::NAV);

        $this->assertSame('cache/brand/ventasync-184x44fit.png', $fitted);
        $this->assertStringContainsString($fitted, BrandImage::url(null, BrandImage::NAV));

        [, $h] = $this->sizeOf($fitted);
        $this->assertLessThanOrEqual(44, $h, 'the shipped mark is inside the box it was given');

        /* A box that is changed asks for a name nothing has written yet, so
           the new size appears without anything being invalidated. */
        $row = Setting::query()->first() ?? Setting::query()->create([]);
        $row->forceFill(['logo_nav_h' => 80])->save();
        BrandImage::forget();

        $this->assertSame('cache/brand/ventasync-184x80fit.png', BrandImage::shipped(BrandImage::NAV));
    }

    /**
     * THE SIZE TRAVELS WITH THE URL, and it is the file's own, never the box.
     *
     * A page that fixes its own height decides the size instead of Settings,
     * which is how a resize comes to do nothing at all. And the box is a
     * ceiling: handing it to the page would stretch back what fitting avoided.
     */
    public function test_the_drawn_size_is_the_fitted_file_not_the_box(): void
    {
        $this->logo('company/wide.png', 1200, 200);

        $shown = BrandImage::show('company/wide.png', BrandImage::NAV);

        $this->assertSame([184, 31], [$shown['width'], $shown['height']],
            'the size on the tag is the copy that is served, short of the box');
        $this->assertStringContainsString('wide-184x44fit.png', $shown['url']);
    }

    /** A file GD cannot measure falls back to the box rather than to nothing. */
    public function test_an_unmeasurable_file_falls_back_to_the_box(): void
    {
        Storage::disk('public')->put('company/mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $shown = BrandImage::show('company/mark.svg', BrandImage::LOGIN);

        $this->assertSame([420, 96], [$shown['width'], $shown['height']]);
        $this->assertStringContainsString('company/mark.svg', $shown['url'], 'an SVG is served as it is');
    }

    /**
     * NEITHER PAGE FIXES THE LOGO'S SIZE ITSELF.
     *
     * This is the defect that hid the whole box: the sidebar pinned h-11 and
     * the sign-in panel pinned 96px, so the box only ever chose which file was
     * fetched, never how large it drew.
     */
    public function test_no_page_pins_the_logo_size(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/blotter.blade.php'));
        $this->assertNotFalse($sidebar);
        $this->assertStringContainsString('BrandImage::show', $sidebar);
        /* A utility height or width on the rail would decide the size before
           the box ever could, which is the defect this pins. */
        $this->assertStringNotContainsString('h-11', $sidebar, 'the rail fixes no height of its own');
        $this->assertStringNotContainsString('max-w-[', $sidebar, 'nor a width: the ceiling lives in WIDTHS');

        $login = file_get_contents(resource_path('views/auth/login.blade.php'));
        $this->assertNotFalse($login);
        $this->assertStringContainsString('BrandImage::show', $login);

        $css = file_get_contents(resource_path('css/blotter-components.css'));
        $this->assertNotFalse($css);
        $rule = substr($css, (int) strpos($css, '.auth__logo {'), 160);
        $this->assertStringNotContainsString('height: 96px', $rule, 'the panel no longer decides the height');
    }
}
