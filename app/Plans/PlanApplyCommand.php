<?php

namespace App\Plans;

use App\Extensions\ExtensionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PlanApplyCommand extends Command
{
    protected $signature = 'plan:apply';

    protected $description = 'Switch on the extensions the hosted plan newly includes';

    private const APPLIED = 'plan-applied.json';

    public function handle(ExtensionManager $manager): int
    {
        $included = Plan::extensions();

        if ($included === null) {
            $this->line('Self-hosted. Nothing to apply.');

            return self::SUCCESS;
        }

        $disk = Storage::disk('local');
        $applied = $disk->exists(self::APPLIED)
            ? (array) json_decode((string) $disk->get(self::APPLIED), true)
            : [];

        $onDisk = array_keys($manager->getManifests());
        $switchedOn = [];

        foreach (array_diff($included, $applied) as $id) {
            if (! in_array($id, $onDisk, true)) {
                continue;
            }

            if ($manager->install($id) && $manager->enable($id)) {
                $switchedOn[] = $id;
            }
        }

        $disk->put(self::APPLIED, json_encode(array_values($included)));

        $this->info($switchedOn === []
            ? 'Plan applied. Nothing new to switch on.'
            : 'Plan applied. Switched on: ' . implode(', ', $switchedOn) . '.');

        return self::SUCCESS;
    }
}
