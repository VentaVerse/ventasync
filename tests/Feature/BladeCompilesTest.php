<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

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
