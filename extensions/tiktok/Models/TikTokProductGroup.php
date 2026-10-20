<?php

namespace Extensions\TikTok\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\TikTok\Models\Concerns\BelongsToTikTokStore;

class TikTokProductGroup extends Model
{
    use BelongsToTikTokStore;

    protected $table = 'ch_tiktok_product_groups';

    protected $fillable = ['store_id', 'name'];

    public function groupProducts()
    {
        return $this->hasMany(TikTokProductGroupProduct::class, 'tiktok_product_group_id');
    }
}
