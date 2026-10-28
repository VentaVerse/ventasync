<?php

namespace Tests;

use Symfony\Component\Finder\Finder;

final class SourceFiles
{
    // File::allFiles() skips a symlinked folder, and Pro extensions are symlinked in during development.
    // An extension's own Tests folder is test code, not source.
    public static function in(string $directory): array
    {
        return iterator_to_array(Finder::create()->files()->ignoreDotFiles(true)->followLinks()->in($directory)->notPath('#(^|/)Tests/#')->sortByName(), false);
    }
}
