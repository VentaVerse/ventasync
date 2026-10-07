<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class NoInternalNotesTest extends TestCase
{
    private const DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'extensions', 'resources/views', 'resources/js', 'resources/css', 'routes'];

    public function test_the_shipped_code_carries_no_internal_notes(): void
    {
        $files = (new Finder())->files()
            ->in(array_map(fn ($dir) => base_path($dir), self::DIRECTORIES))
            ->name(['*.php', '*.js', '*.css']);

        $this->assertGreaterThan(500, iterator_count($files), 'the scan found the source files');

        $found = [];
        foreach ($files as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                $isComment = preg_match('#^\s*(//|/\*|\*|\{\{--|<!--)#', $line) === 1;
                if (preg_match('/\bpaolo\b|\bVENT2-\d+/i', $line)
                    || ($isComment && preg_match('/\b20\d\d-\d\d-\d\d\b(?!.*overflow)|\(#\d+/', $line))) {
                    $found[] = $file->getRelativePathname() . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame([], $found, "Internal notes in shipped code:\n" . implode("\n", $found));
    }
}
