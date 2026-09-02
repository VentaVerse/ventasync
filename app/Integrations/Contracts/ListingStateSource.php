<?php

namespace App\Integrations\Contracts;

use App\Integrations\Listings\ListingState;

/**
 * A channel that can answer, per catalogue product, where its listing
 * stands (App\Integrations\Listings\ListingState). Implemented on the
 * extension's IntegrationProvider, discovered through the registry like
 * every other contributor.
 *
 * This is the contract card #97 promised and #184's All Listings grid
 * will consume: core surfaces ask every enabled channel the same
 * question and render the same vocabulary, without knowing which
 * channels are installed.
 */
interface ListingStateSource
{
    /**
     * @param list<int> $productIds
     * @return array<int, ListingState> keyed by product id; a product the
     *         channel knows nothing about may be omitted.
     */
    public function listingStates(array $productIds): array;

    /**
     * Where a person ACTS on each product's listing for this channel - the
     * channel's own workbench page, keyed by product id (null entries when
     * the channel has nowhere to send them). Batch-shaped like
     * listingStates(), so a fifty-row grid costs one lookup per channel.
     * Core renders the links verbatim and never builds channel URLs itself.
     *
     * @param list<int> $productIds
     * @return array<int, ?string>
     */
    public function listingUrls(array $productIds): array;
}
