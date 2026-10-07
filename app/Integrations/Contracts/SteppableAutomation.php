<?php

namespace App\Integrations\Contracts;

use App\Models\ScheduledJob;

interface SteppableAutomation
{
    public function automationUnits(ScheduledJob $job): array;

    public function automationStep(ScheduledJob $job, array $units): array;
}
