<?php

namespace App\Integrations\Contracts;

interface ProductActionContributor
{
    public function productActions(int $productId): array;
}
