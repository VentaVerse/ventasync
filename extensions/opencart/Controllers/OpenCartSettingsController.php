<?php

namespace Extensions\opencart\Controllers;

/**
 * The OpenCart connection: credentials, stores, status maps, logging.
 *
 * A thin subclass so these routes derive their own key (opencart/settings)
 * instead of the retired bundle key (opencart/overview). All logic stays in
 * OpenCartController; the catalogue keys on the routed class, so the split is a
 * routing fact, not a code move. See LazadaSettingsController for the
 * full reasoning.
 */
class OpenCartSettingsController extends OpenCartController
{
}
