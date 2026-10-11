<?php

namespace App\Channels\Contracts;

interface SkuResolver
{
    public function resolveCatalogProduct(string $sku): ?array;
}
