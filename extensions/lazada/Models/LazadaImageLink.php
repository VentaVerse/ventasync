<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaImageLink extends Model
{
    protected $table = 'ch_lazada_image_links';

    protected $fillable = [
        'region',
        'original_url',
        'original_hash',
        'lazada_url',
    ];
}
