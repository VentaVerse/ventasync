<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductVariant extends Model
{
    // Renamed in DB to lazada_product_variants (kept model name for backward compatibility)
    protected $table = 'lazada_product_variants';

    /* price and quantity were missing here, so every variation override
     * typed on the listing page was dropped SILENTLY on the way to the
     * table: the box kept its value on screen, the row saved a null, and
     * the push sent the catalog's figures. The same disease the listing's
     * own markups had. Found 2026-09-07 by the one-Save test. */
    protected $fillable = [
        'lazada_product_id', 'seller_sku', 'sku_id', 'shop_sku', 'product_option_value_id',
        'price', 'quantity',
    ];
}
