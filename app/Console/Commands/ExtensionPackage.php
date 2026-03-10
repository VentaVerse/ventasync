<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

class ExtensionPackage extends Command
{
    protected $signature = 'extension:package {id}';
    protected $description = 'Package an extension into a .erpx archive';

    public function handle(): int
    {
        $id = $this->argument('id');
        $extensionDir = base_path('extensions/' . $id);

        if (!File::isDirectory($extensionDir)) {
            $this->error("Extension directory not found: extensions/{$id}/");
            return self::FAILURE;
        }

        $manifestFile = $extensionDir . '/extension.json';

        if (!File::exists($manifestFile)) {
            $this->error("No extension.json found in extensions/{$id}/");
            return self::FAILURE;
        }

        $manifest = json_decode(File::get($manifestFile), true);
        $version = $manifest['version'] ?? '1.0.0';

        $outputFile = base_path("{$id}-v{$version}.erpx");

        $zip = new ZipArchive();
        if ($zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Failed to create archive: {$outputFile}");
            return self::FAILURE;
        }

        $files = File::allFiles($extensionDir);

        foreach ($files as $file) {
            $relativePath = $id . '/' . $file->getRelativePathname();
            $zip->addFile($file->getRealPath(), $relativePath);
        }

        $zip->close();

        $this->info("Package created: {$outputFile}");

        return self::SUCCESS;
    }
}
