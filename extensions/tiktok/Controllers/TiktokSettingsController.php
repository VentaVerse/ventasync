<?php

namespace Extensions\tiktok\Controllers;

/**
 * The Tiktok connection: credentials, tokens, status maps, logging.
 *
 * A thin subclass so these routes derive their own key (tiktok/settings)
 * instead of the retired bundle key (tiktok/overview). All logic stays in
 * TikTokController; the catalogue keys on the routed class, so the split is a
 * routing fact, not a code move. See LazadaSettingsController for the
 * full reasoning.
 */
class TiktokSettingsController extends TikTokController
{
}
