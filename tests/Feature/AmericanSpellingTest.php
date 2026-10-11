<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AmericanSpellingTest extends TestCase
{
    private const DIRECTORIES = ['app', 'config', 'database', 'extensions', 'resources/views', 'resources/js', 'resources/css', 'routes', 'tests'];

    // The old command name stays as an alias so an existing cron line keeps working.
    private const ALLOWED = ["\$aliases = ['permissions:sync-catal"];

    public function test_our_own_words_use_american_spelling(): void
    {
        $british = '/(catal' . 'ogue|col' . 'our|recogn' . 'is|behavi' . 'our)/i';
        $files = [];
        foreach (self::DIRECTORIES as $directory) {
            $this->assertDirectoryExists(base_path($directory));
            foreach (File::allFiles(base_path($directory)) as $file) {
                if (in_array($file->getExtension(), ['php', 'js', 'css', 'json', 'md'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        $this->assertGreaterThan(500, count($files));

        $found = [];
        foreach ($files as $path) {
            foreach (preg_split('/\R/', File::get($path)) as $i => $line) {
                if (preg_match($british, $line, $m) && ! $this->allowed($line)) {
                    $found[] = str_replace(base_path() . '/', '', $path) . ':' . ($i + 1) . '  ' . $m[1];
                }
            }
        }

        $this->assertSame([], $found, "British spelling found:\n  " . implode("\n  ", $found));
    }

    private function allowed(string $line): bool
    {
        foreach (self::ALLOWED as $allowed) {
            if (str_contains($line, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
