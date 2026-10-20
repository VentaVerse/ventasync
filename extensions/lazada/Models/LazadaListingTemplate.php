<?php

namespace Extensions\Lazada\Models;

use Extensions\Lazada\Models\Concerns\BelongsToLazadaStore;
use Illuminate\Database\Eloquent\Model;

class LazadaListingTemplate extends Model
{
    use BelongsToLazadaStore;

    protected $table = 'ch_lazada_listing_templates';

    protected $fillable = [
        'store_id', 'name', 'primary_category_id', 'brand_id', 'brand_name_override',
        'markup_percent', 'markup_fixed', 'watermark_template_id',
    ];

    public function templateAttributes()
    {
        return $this->hasMany(LazadaListingTemplateAttribute::class, 'listing_template_id');
    }
}
