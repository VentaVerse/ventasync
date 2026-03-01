<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = ['code', 'name', 'symbol', 'exchange_rate', 'is_default', 'status'];

    protected $casts = [
        'exchange_rate' => 'decimal:8',
        'is_default' => 'boolean',
    ];
}
