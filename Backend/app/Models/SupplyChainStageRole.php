<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class SupplyChainStageRole extends Model
{
    use Tenantable;

    protected $fillable = ['tenant_id', 'stage', 'job_role_id'];

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class, 'job_role_id');
    }
}
