<?php

namespace Extensions\TikTok\Models;

use Extensions\TikTok\Models\Concerns\BelongsToTikTokStore;
use Illuminate\Database\Eloquent\Model;

class TikTokListingTemplate extends Model
{
    use BelongsToTikTokStore;

    protected $table = 'ch_tiktok_listing_templates';

    protected $fillable = [
        'store_id', 'name', 'tiktok_category_id', 'markup_percent', 'markup_fixed', 'watermark_template_id',
    ];

    public function templateAttributes()
    {
        return $this->hasMany(TikTokListingTemplateAttribute::class, 'listing_template_id');
    }
}
