<?php

namespace App\Integrations\Contracts;

interface MarketplaceSourceLabelResolver
{
    public function resolveSourceLabel(string $source): ?string;
}
