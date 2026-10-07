<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

class UserGroup extends Model
{
    protected $fillable = ['name'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'user_group_permissions', 'user_group_id', 'permission_id');
    }
}
