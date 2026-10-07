<?php

namespace Tests\Feature;

use Tests\TestCase;

class FormBarTest extends TestCase
{
    private function read(string $relative): string
    {
        $this->assertFileExists(base_path($relative));

        return (string) file_get_contents(base_path($relative));
    }

    public function test_the_bar_draws_its_buttons_in_the_one_order(): void
    {
        $bar = $this->read('resources/views/components/ui/form-bar.blade.php');

        $note = strpos($bar, 'fm-bar__meta');
        $cancel = strpos($bar, 'fm-cancel');
        $secondary = strpos($bar, '{{ $slot }}');
        $primary = strpos($bar, '{{ $primary');

        $this->assertNotFalse($note);
        $this->assertTrue($note < $cancel && $cancel < $secondary && $secondary < $primary,
            'the bar reads note, Cancel, secondary, primary');
        $this->assertStringContainsString('data-guard-leave', $bar, 'Cancel asks before leaving unsaved work');
    }

    public function test_no_page_writes_the_bar_by_hand(): void
    {
        $views = array_merge(
            glob(base_path('resources/views/**/*.blade.php')) ?: [],
            glob(base_path('resources/views/*/*/*.blade.php')) ?: [],
            glob(base_path('resources/views/*/*/*/*.blade.php')) ?: [],
            glob(base_path('extensions/*/views/*.blade.php')) ?: [],
            glob(base_path('extensions/*/views/*/*.blade.php')) ?: [],
            glob(base_path('extensions/*/views/*/*/*.blade.php')) ?: [],
        );

        $offenders = [];
        foreach (array_unique($views) as $path) {
            if (str_ends_with($path, 'components/ui/form-bar.blade.php')) {
                continue;
            }
            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
            if (preg_match('/class="fm-bar[" ]/', $markup)) {
                $offenders[] = str_replace(base_path() . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders, 'these pages draw the bar by hand instead of <x-ui.form-bar>');
    }

    public function test_the_bar_is_thumb_sized_on_a_phone_and_never_covers_the_field_in_focus(): void
    {
        $css = $this->read('resources/css/blotter-components.css');

        $this->assertStringContainsString('safe-area-inset-bottom', $css);
        $this->assertStringContainsString('scroll-padding-bottom', $css);
        $this->assertMatchesRegularExpression('/\.fm-bar__actions > \.x-btn \{[^}]*min-height: 44px/s', $css);
    }
}
