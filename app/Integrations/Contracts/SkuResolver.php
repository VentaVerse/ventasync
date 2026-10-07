<?php

namespace App\Integrations\Contracts;

interface SkuResolver
{
    public function resolveCatalogProduct(string $sku): ?array;
}
