<?php

namespace Extensions\shopee\Controllers;

/**
 * The Shopee connection: credentials, tokens, status maps, logging.
 *
 * A thin subclass so these routes derive their own key (shopee/settings)
 * instead of the retired bundle key (shopee/overview). All logic stays in
 * ShopeeController; the catalogue keys on the routed class, so the split is a
 * routing fact, not a code move. See LazadaSettingsController for the
 * full reasoning.
 */
class ShopeeSettingsController extends ShopeeController
{
}
