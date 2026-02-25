<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProfileAttribute extends Model
{
    protected $table = 'lazada_profile_attributes';

    protected $fillable = ['lazada_profile_id', 'attribute_key', 'value'];
}
