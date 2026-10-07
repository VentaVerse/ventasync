<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionManager;
use Illuminate\Console\Command;

class ExtensionList extends Command
{
    protected $signature = 'extension:list';
    protected $description = 'List all extensions and their status';

    public function handle(ExtensionManager $manager): int
    {
        $extensions = $manager->all();

        if (empty($extensions)) {
            $this->info('No extensions found.');
            return self::SUCCESS;
        }

        $rows = [];

        foreach ($extensions as $ext) {
            if ($ext['installed'] && $ext['enabled']) {
                $status = 'Enabled';
            } elseif ($ext['installed']) {
                $status = 'Disabled';
            } else {
                $status = 'Not installed';
            }

            if (!$ext['license_required']) {
                $licensed = 'Free';
            } elseif (!empty($ext['license_key'])) {
                $licensed = 'Yes';
            } else {
                $licensed = 'No';
            }

            $rows[] = [
                $ext['id'],
                $ext['name'],
                $ext['version'],
                $status,
                $licensed,
            ];
        }

        $this->table(['ID', 'Name', 'Version', 'Status', 'Licensed'], $rows);

        return self::SUCCESS;
    }
}
