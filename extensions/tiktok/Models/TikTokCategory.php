<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokCategory extends Model
{
    protected $table = 'tiktok_categories';

    // String PK, not auto-increment
    protected $keyType = 'string';
    public $incrementing = false;

    // Cache table — no timestamps
    public $timestamps = false;

    protected $fillable = [
        'id', 'parent_id', 'name', 'is_leaf', 'permission_statuses', 'synced_at',
    ];

    protected $casts = [
        'is_leaf' => 'boolean',
        'permission_statuses' => 'array',
        'synced_at' => 'datetime',
    ];
}
