<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionManager;
use Illuminate\Console\Command;

class ExtensionUninstall extends Command
{
    protected $signature = 'extension:uninstall {id} {--delete-files : Also remove extension files}';
    protected $description = 'Uninstall an extension';

    public function handle(ExtensionManager $manager): int
    {
        $id = $this->argument('id');
        $deleteFiles = $this->option('delete-files');

        $manifest = $manager->getManifest($id);

        if ($manifest === null) {
            $this->error("Extension '{$id}' not found.");
            return self::FAILURE;
        }

        $name = $manifest['name'] ?? $id;

        $message = $deleteFiles
            ? "Uninstall '{$name}' and delete all its files?"
            : "Uninstall '{$name}'? (files will be kept)";

        if (!$this->confirm($message)) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $manager->uninstall($id, $deleteFiles);

        $suffix = $deleteFiles ? ' and files deleted' : '';
        $this->info("Extension '{$name}' uninstalled{$suffix}.");

        return self::SUCCESS;
    }
}
