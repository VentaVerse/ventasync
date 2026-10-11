<?php

namespace App\Channels\Contracts;

interface VariationForgetter
{
    public function forgetVariations(int $productId, array $skus): void;
}
