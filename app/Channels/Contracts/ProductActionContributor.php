<?php

namespace App\Channels\Contracts;

interface ProductActionContributor
{
    public function productActions(int $productId): array;
}
