<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every Blade view compiles to valid PHP.
 *
 * This exists because of a trap that cost a working feature and produced no
 * failing test. Blade extracts `@php ... @endphp` blocks with a NON-GREEDY
 * regex before it compiles directives, so an inline `@php($x = 1)` earlier in
 * a file pairs with the FIRST `@endphp` that appears anywhere after it:
 *
 *     @php($a = 1)          <- inline form, 40 lines up
 *     ...                   <- everything here is swallowed
 *     @php                  <- a block added later, by someone else
 *      $b = 2;
 *     @endphp               <- closes the INLINE one
 *
 * The inline directive is then emitted as a bare `<?php($a = 1)` with no
 * closing tag, and every line between the two is passed through raw. Nothing
 * detects it until the page 500s, and the page that 500s is not the page that
 * changed - adding a `@php` block at the bottom of a file breaks a modal at
 * the top. The suite here was green throughout.
 *
 * Compiling every view is the cheap general guard. It also catches an
 * unclosed `@if`, a stray `@endforeach`, and a directive typo, none of which
 * a view test finds unless it happens to render that exact branch.
 */
class BladeCompilesTest extends TestCase
{
    public function test_every_blade_view_compiles_to_valid_php(): void
    {
        $roots = array_merge(
            [resource_path('views')],
            glob(base_path('extensions/*/views')) ?: [],
        );

        $views = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $views[] = $file->getPathname();
                }
            }
        }

        $this->assertNotEmpty($views, 'no Blade views found to compile');

        $compiler = app('blade.compiler');
        $tmp = tempnam(sys_get_temp_dir(), 'blade-compiles-').'.php';
        $broken = [];

        try {
            foreach ($views as $view) {
                $php = $compiler->compileString(File::get($view));
                File::put($tmp, $php);

                $output = [];
                $status = 0;
                exec(escapeshellcmd(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $output, $status);

                if ($status !== 0) {
                    $message = implode(' ', $output);
                    // The temp path is noise; the view path is the finding.
                    $message = str_replace($tmp, '', $message);
                    $broken[] = str_replace(base_path().'/', '', $view).' - '.trim($message);
                }
            }
        } finally {
            @unlink($tmp);
        }

        $this->assertSame([], $broken, sprintf(
            "%d Blade view(s) do not compile to valid PHP:\n\n  %s\n\n"
            ."A common cause is mixing the inline @php(\$x = 1) form with a @php ... @endphp\n"
            ."block in the same file: the block's @endphp closes the INLINE one, and every\n"
            ."line between them is emitted raw. Use the block form throughout a file.",
            count($broken),
            implode("\n  ", $broken)
        ));
    }
}
