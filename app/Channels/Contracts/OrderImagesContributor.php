<?php

namespace App\Channels\Contracts;

interface OrderImagesContributor
{
    public function imagesForCatalogOrders(array $catalogOrderIds): array;
}
