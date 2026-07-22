<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class SillaRequest extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id', 'store_id', 'employee_id', 'requested_at', 'status',
        'approved_by_employee_id', 'approval_method', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];
}
