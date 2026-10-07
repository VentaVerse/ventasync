<?php

namespace App\Integrations\Contracts;

interface MarketplaceSourceOptionsProvider extends MarketplaceSourceLabelResolver
{
    public function availableSourceOptions(): array;
}
