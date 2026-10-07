<?php

namespace App\Integrations\Contracts;

interface VariationForgetter
{
    public function forgetVariations(int $productId, array $skus): void;
}
