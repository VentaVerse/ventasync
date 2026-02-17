<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductVariant extends Model
{
    // Renamed in DB to lazada_product_variants (kept model name for backward compatibility)
    protected $table = 'lazada_product_variants';

    protected $guarded = [];
}
