<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaListing extends Model
{
    protected $table = 'lazada_listings';

    protected $guarded = [];

    public function attributes()
    {
        return $this->hasMany(LazadaListingAttribute::class, 'lazada_listing_id');
    }

    public function variants()
    {
        return $this->hasMany(LazadaListingVariant::class, 'lazada_listing_id');
    }
}
