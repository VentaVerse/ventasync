<?php

namespace App\Integrations\Contracts;

interface OrderImagesContributor
{
    public function imagesForCatalogOrders(array $catalogOrderIds): array;
}
