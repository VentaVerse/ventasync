<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionManager;
use Illuminate\Console\Command;

class ExtensionDisable extends Command
{
    protected $signature = 'extension:disable {id}';
    protected $description = 'Disable an installed extension';

    public function handle(ExtensionManager $manager): int
    {
        $id = $this->argument('id');

        if (!$manager->disable($id)) {
            $this->error("Extension '{$id}' not found or not installed.");
            return self::FAILURE;
        }

        $this->info("Extension '{$id}' disabled.");

        return self::SUCCESS;
    }
}
