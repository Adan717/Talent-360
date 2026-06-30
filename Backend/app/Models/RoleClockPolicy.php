<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class RoleClockPolicy extends Model
{
    use Tenantable;

    use HasFactory;

    protected $fillable = [
        'job_role_id',
        'policy_name',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class);
    }
}
