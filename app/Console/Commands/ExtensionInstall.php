<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use ZipArchive;

class ExtensionInstall extends Command
{
    protected $signature = 'extension:install {path : Path to .erpx or .zip file}';
    protected $description = 'Install an extension from a .erpx or .zip archive';

    public function handle(ExtensionManager $manager): int
    {
        $path = $this->argument('path');

        if (!File::exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $this->error("Failed to open archive: {$path}");
            return self::FAILURE;
        }

        // Extract to temp directory
        $tempDir = sys_get_temp_dir() . '/erpx-' . uniqid();
        $zip->extractTo($tempDir);
        $zip->close();

        // Find extension.json — may be at root or one level deep
        $manifestPath = null;
        $manifestBase = null;

        if (File::exists($tempDir . '/extension.json')) {
            $manifestPath = $tempDir . '/extension.json';
            $manifestBase = $tempDir;
        } else {
            // Check one level deep
            foreach (File::directories($tempDir) as $dir) {
                if (File::exists($dir . '/extension.json')) {
                    $manifestPath = $dir . '/extension.json';
                    $manifestBase = $dir;
                    break;
                }
            }
        }

        if ($manifestPath === null) {
            File::deleteDirectory($tempDir);
            $this->error('No extension.json found in archive.');
            return self::FAILURE;
        }

        $manifest = json_decode(File::get($manifestPath), true);

        if (!is_array($manifest) || empty($manifest['id'])) {
            File::deleteDirectory($tempDir);
            $this->error('extension.json is missing a valid "id" field.');
            return self::FAILURE;
        }

        $id = $manifest['id'];
        $name = $manifest['name'] ?? $id;
        $version = $manifest['version'] ?? '1.0.0';
        $targetDir = base_path('extensions/' . $id);

        // Copy to extensions/{id}/ (overwrites if updating)
        if (File::isDirectory($targetDir)) {
            File::deleteDirectory($targetDir);
        }
        File::copyDirectory($manifestBase, $targetDir);

        // Clean up temp files
        File::deleteDirectory($tempDir);

        // Register in DB
        if (!$manager->install($id)) {
            $this->error("Failed to register extension '{$id}' in database.");
            return self::FAILURE;
        }

        // Run migrations
        Artisan::call('migrate', ['--force' => true]);
        $migrateOutput = trim(Artisan::output());
        if ($migrateOutput) {
            $this->line($migrateOutput);
        }

        // Dump autoload
        Process::run('composer dump-autoload -d ' . base_path());

        $this->info("Extension '{$name}' v{$version} installed successfully.");

        return self::SUCCESS;
    }
}
