<?php

namespace App\Channels\Contracts;

use App\Channels\Listings\ListingState;

interface ListingStateSource
{
    public function listingStates(array $productIds): array;

    public function listingUrls(array $productIds): array;

    public function listedProductIds(): array;
}
