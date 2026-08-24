<?php

namespace Extensions\lazada\Controllers;

/**
 * The Lazada connection: credentials, tokens, OAuth, status maps, logging.
 *
 * A THIN SUBCLASS, ON PURPOSE. Every method still lives in LazadaController;
 * this class exists so the settings routes derive their own key
 * (lazada/settings) instead of sharing one with the API explorer. The old
 * single key (lazada/settings) bundled four jobs, one of which - sending
 * requests to Lazada as this shop - is a different grant decision from
 * "may edit the connection settings". The catalog keys on the routed
 * class, so the split is a routing fact, not a code move.
 */
class LazadaSettingsController extends LazadaController
{
}
