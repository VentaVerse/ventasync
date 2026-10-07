<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NoEmDashReachesAPersonTest extends TestCase
{
    private const DASH = "\u{2014}";

    private const ROOTS = ['app', 'extensions', 'resources', 'routes', 'config', 'lang', 'database'];

    public function test_no_em_dash_is_shown_anywhere(): void
    {
        $files = 0;
        $found = [];

        foreach (self::ROOTS as $root) {
            $dir = base_path($root);
            if (! is_dir($dir)) {
                continue;
            }
            foreach (File::allFiles($dir) as $file) {
                $path = $file->getPathname();
                $text = File::get($path);
                $files++;
                if (! str_contains($text, self::DASH)) {
                    continue;
                }

                $lines = match (true) {
                    str_ends_with($path, '.blade.php') => $this->phpLines($this->compiled($text, $path)),
                    str_ends_with($path, '.php') => $this->phpLines($text),
                    str_ends_with($path, '.js') => $this->scriptLines($text),
                    str_ends_with($path, '.css') => $this->styleLines($text),
                    default => [],
                };

                foreach ($lines as $line) {
                    $found[] = str_replace(base_path() . '/', '', $path) . ':' . $line;
                }
            }
        }

        $this->assertGreaterThan(500, $files, 'the scan found the source');
        $this->assertSame(
            [],
            $found,
            "An em dash is shown to a person. Use a comma, a colon or a full stop, or \"-\" for a missing value:\n  "
            . implode("\n  ", $found)
        );
    }

    private function compiled(string $text, string $path): string
    {
        try {
            return Blade::compileString($text);
        } catch (\Throwable $e) {
            $this->fail("{$path} does not compile: {$e->getMessage()}");
        }
    }

    private function phpLines(string $code): array
    {
        $lines = [];
        foreach (token_get_all($code) as $token) {
            if (! is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (str_contains($token[1], self::DASH)) {
                $lines[] = $this->firstLineWithDash($token[1], $token[2]);
            }
        }

        return array_values(array_unique($lines));
    }

    private function scriptLines(string $code): array
    {
        $code = preg_replace_callback('#/\*.*?\*/#s', fn ($m) => preg_replace('/[^\n]/', ' ', $m[0]), $code);
        $code = preg_replace('#(^|[\s;{}(,])//[^\n]*#', '$1', $code);

        return $this->linesWithDash($code);
    }

    private function styleLines(string $code): array
    {
        $code = preg_replace_callback('#/\*.*?\*/#s', fn ($m) => preg_replace('/[^\n]/', ' ', $m[0]), $code);

        return $this->linesWithDash($code);
    }

    private function linesWithDash(string $code): array
    {
        $lines = [];
        foreach (explode("\n", $code) as $i => $line) {
            if (str_contains($line, self::DASH)) {
                $lines[] = $i + 1;
            }
        }

        return $lines;
    }

    private function firstLineWithDash(string $text, int $startLine): int
    {
        return $startLine + substr_count(strstr($text, self::DASH, true), "\n");
    }
}
