<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProfileAttribute extends Model
{
    protected $table = 'shopee_profile_attributes';

    protected $fillable = ['shopee_profile_id', 'attribute_key', 'value'];
}
