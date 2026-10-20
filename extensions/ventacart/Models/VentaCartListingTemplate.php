<?php

namespace Extensions\VentaCart\Models;

use Illuminate\Database\Eloquent\Model;

class VentaCartListingTemplate extends Model
{
    protected $table = 'ch_ventacart_listing_templates';

    protected $fillable = ['store_id', 'name', 'ventacart_category_id', 'markup_percent', 'markup_fixed', 'watermark_template_id'];
}
