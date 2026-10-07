<?php

namespace App\Integrations\Contracts;

interface DashboardContributor
{
    public function dashboardData(): array;
}
