<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionManager;
use Illuminate\Console\Command;

class ExtensionEnable extends Command
{
    protected $signature = 'extension:enable {id}';
    protected $description = 'Enable an installed extension';

    public function handle(ExtensionManager $manager): int
    {
        $id = $this->argument('id');

        if (!$manager->enable($id)) {
            $this->error("Extension '{$id}' not found or not installed.");
            return self::FAILURE;
        }

        $this->info("Extension '{$id}' enabled.");

        return self::SUCCESS;
    }
}
