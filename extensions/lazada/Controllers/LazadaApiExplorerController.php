<?php

namespace Extensions\lazada\Controllers;

/**
 * The Lazada API explorer: raw calls, request packs, and the explorer's
 * fetch endpoints - everything that ACTS AS THE SHOP against Lazada's API,
 * outside the ERP's own record keeping.
 *
 * Split from the settings key so an owner can grant "may edit the
 * connection" without granting "may send requests to Lazada as me". See
 * LazadaSettingsController for the thin-subclass reasoning.
 */
class LazadaApiExplorerController extends LazadaController
{
}
