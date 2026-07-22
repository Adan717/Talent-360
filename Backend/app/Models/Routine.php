<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Routine extends Model
{
    use Tenantable, HasFactory, SoftDeletes;

    protected $fillable = [
        'id', 'title', 'target_role_id', 'trigger', 'assign_mode'
    ];

    public function tasks()
    {
        return $this->belongsToMany(Task::class);
    }
}
