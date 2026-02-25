<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpenCartProfileProduct extends Model
{
    protected $table = 'opencart_profile_products';

    public $timestamps = false;

    protected $fillable = ['opencart_profile_id', 'product_id'];

    public function profile()
    {
        return $this->belongsTo(OpenCartProfile::class, 'opencart_profile_id');
    }
}
