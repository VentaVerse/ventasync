<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaImageLink extends Model
{
    protected $table = 'lazada_image_links';

    protected $fillable = [
        'region',
        'original_url',
        'original_hash',
        'lazada_url',
    ];
}
