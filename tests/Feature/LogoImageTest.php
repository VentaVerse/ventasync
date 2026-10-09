<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Media\LogoImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogoImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        LogoImage::forget();
    }

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

    public function test_a_wide_lockup_is_fitted_by_its_width(): void
    {
        $this->logo('company/wide.png', 1200, 200);

        $fitted = LogoImage::fitted('company/wide.png', LogoImage::NAV);

        $this->assertSame('cache/company/wide-184x44fit.png', $fitted, 'the box is in the name, in the one flat cache');
        $this->assertSame([184, 31], $this->sizeOf($fitted));
    }

    public function test_a_square_mark_is_fitted_by_its_height(): void
    {
        $this->logo('company/mark.png', 500, 500);

        $fitted = LogoImage::fitted('company/mark.png', LogoImage::NAV);

        $this->assertSame([44, 44], $this->sizeOf($fitted), 'a square mark lands at the box height, square');
    }

    public function test_the_mark_keeps_its_transparency(): void
    {
        $this->logo('company/alpha.png', 400, 100);

        $fitted = LogoImage::fitted('company/alpha.png', LogoImage::LOGIN);
        $im = imagecreatefrompng(Storage::disk('public')->path($fitted));
        $corner = imagecolorsforindex($im, imagecolorat($im, 0, 0));

        $this->assertSame(127, $corner['alpha'], 'the corner is still fully transparent');
    }

    public function test_a_small_logo_is_left_at_its_own_size(): void
    {
        $this->logo('company/small.png', 90, 20);

        $this->assertSame([90, 20], $this->sizeOf(LogoImage::fitted('company/small.png', LogoImage::NAV)));
    }

    public function test_each_place_keeps_its_own_box(): void
    {
        $this->logo('company/two.png', 1200, 200);

        $nav = LogoImage::fitted('company/two.png', LogoImage::NAV);
        $login = LogoImage::fitted('company/two.png', LogoImage::LOGIN);
        $this->assertNotSame($nav, $login, 'the two places have their own room and their own copy');

        $row = Setting::query()->first() ?? Setting::query()->create([]);
        $row->forceFill(['logo_nav_h' => 72])->save();
        LogoImage::forget();

        $this->assertSame('cache/company/two-184x72fit.png',
            LogoImage::fitted('company/two.png', LogoImage::NAV),
            'the box names the file, so a new height asks for a name nothing has written yet');
        $this->assertSame($login, LogoImage::fitted('company/two.png', LogoImage::LOGIN),
            'and the other place is untouched by it');
    }

    public function test_the_width_is_the_columns_own_and_never_a_field(): void
    {
        $this->assertArrayNotHasKey('w', LogoImage::LIMITS, 'no width is offered for typing');

        foreach ([LogoImage::NAV, LogoImage::LOGIN] as $place) {
            [$w, $h] = LogoImage::box($place);
            $this->assertSame(LogoImage::WIDTHS[$place], $w, 'the width is the column, whatever is stored');
            $this->assertSame(LogoImage::height($place), $h);
        }

        $tab = file_get_contents(resource_path('views/settings/partials/tab-website.blade.php'));
        $this->assertNotFalse($tab);
        $this->assertStringContainsString('logo_{{ $place }}_h', $tab);
        $this->assertStringNotContainsString('logo_{{ $place }}_w', $tab, 'the page offers one field per place');
        $this->assertStringNotContainsString('logo_nav_w', $tab);
        $this->assertStringNotContainsString('logo_login_w', $tab);
    }

    public function test_it_never_shows_nothing(): void
    {
        $this->assertStringContainsString('ventasync', LogoImage::url(null, LogoImage::NAV));
        $this->assertStringContainsString('ventasync', LogoImage::url('  ', LogoImage::NAV));

        Storage::disk('public')->put('company/notapicture.png', 'this is not a picture');
        $this->assertStringContainsString('company/notapicture.png',
            LogoImage::url('company/notapicture.png', LogoImage::NAV),
            'a file GD cannot read is served as it is rather than not at all');

        $this->assertNull(LogoImage::fitted('company/../../etc/passwd', LogoImage::NAV));
    }

    public function test_the_shipped_mark_is_fitted_like_any_other(): void
    {
        $fitted = LogoImage::shipped(LogoImage::NAV);

        $this->assertSame('cache/brand/ventasync-184x44fit.png', $fitted);
        $this->assertStringContainsString($fitted, LogoImage::url(null, LogoImage::NAV));

        [, $h] = $this->sizeOf($fitted);
        $this->assertLessThanOrEqual(44, $h, 'the shipped mark is inside the box it was given');

        $row = Setting::query()->first() ?? Setting::query()->create([]);
        $row->forceFill(['logo_nav_h' => 80])->save();
        LogoImage::forget();

        $this->assertSame('cache/brand/ventasync-184x80fit.png', LogoImage::shipped(LogoImage::NAV));
    }

    public function test_the_drawn_size_is_the_fitted_file_not_the_box(): void
    {
        $this->logo('company/wide.png', 1200, 200);

        $shown = LogoImage::show('company/wide.png', LogoImage::NAV);

        $this->assertSame([184, 31], [$shown['width'], $shown['height']],
            'the size on the tag is the copy that is served, short of the box');
        $this->assertStringContainsString('wide-184x44fit.png', $shown['url']);
    }

    public function test_an_unmeasurable_file_falls_back_to_the_box(): void
    {
        Storage::disk('public')->put('company/mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $shown = LogoImage::show('company/mark.svg', LogoImage::LOGIN);

        $this->assertSame([420, 96], [$shown['width'], $shown['height']]);
        $this->assertStringContainsString('company/mark.svg', $shown['url'], 'an SVG is served as it is');
    }

    public function test_no_page_pins_the_logo_size(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/blotter.blade.php'));
        $this->assertNotFalse($sidebar);
        $this->assertStringContainsString('LogoImage::show', $sidebar);
        $this->assertStringNotContainsString('h-11', $sidebar, 'the rail fixes no height of its own');
        $this->assertStringNotContainsString('max-w-[', $sidebar, 'nor a width: the ceiling lives in WIDTHS');

        $login = file_get_contents(resource_path('views/auth/login.blade.php'));
        $this->assertNotFalse($login);
        $this->assertStringContainsString('LogoImage::show', $login);

        $css = file_get_contents(resource_path('css/blotter-components.css'));
        $this->assertNotFalse($css);
        $rule = substr($css, (int) strpos($css, '.auth__logo {'), 160);
        $this->assertStringNotContainsString('height: 96px', $rule, 'the panel no longer decides the height');
    }
}
