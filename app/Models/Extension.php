<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Extension extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'version',
        'description',
        'author',
        'enabled',
        'license_key',
        'manifest',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'manifest' => 'array',
    ];
}
