<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    public $timestamps = false;
    protected $fillable = ['key','label','description'];

    public function userGroups()
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_permissions', 'permission_id', 'user_group_id');
    }
}
