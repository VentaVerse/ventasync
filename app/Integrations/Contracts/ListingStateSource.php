<?php

namespace App\Integrations\Contracts;

use App\Integrations\Listings\ListingState;

interface ListingStateSource
{
    public function listingStates(array $productIds): array;

    public function listingUrls(array $productIds): array;

    public function listedProductIds(): array;
}
