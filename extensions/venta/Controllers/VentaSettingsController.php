<?php

namespace Extensions\venta\Controllers;

/**
 * The Venta connection: credentials, stores, status maps, logging.
 *
 * A thin subclass so these routes derive their own key (venta/settings)
 * instead of the retired bundle key (venta/overview). All logic stays in
 * VentaController; the catalogue keys on the routed class, so the split is a
 * routing fact, not a code move. See LazadaSettingsController for the
 * full reasoning.
 */
class VentaSettingsController extends VentaController
{
}
