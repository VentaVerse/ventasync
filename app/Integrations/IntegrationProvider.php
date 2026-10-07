<?php

namespace App\Integrations;

interface IntegrationProvider
{
    public function integrationId(): string;

    public function integrationCards(): array;

    public function orderTabs(): array;
}
