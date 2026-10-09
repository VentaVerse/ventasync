<?php

namespace App\Channels\Contracts;

interface ProductRemover
{
    public function productPresence(array $productIds): array;

    public function removeProduct(int $productId): array;

    public function strandedProductIds(): array;
}
