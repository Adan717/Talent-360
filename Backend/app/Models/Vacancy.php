<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Tenantable;

class Vacancy extends Model
{
    use Tenantable, SoftDeletes;
    protected $guarded = [];

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class, 'job_role_id');
    }
}
