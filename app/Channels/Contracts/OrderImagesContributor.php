<?php

namespace App\Channels\Contracts;

interface OrderImagesContributor
{
    public function imagesForSalesOrders(array $salesOrderIds): array;
}
