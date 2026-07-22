<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class DoorNotice extends Model
{
    use Tenantable;

    protected $fillable = ['tenant_id', 'from_employee_id', 'to_employee_id', 'date', 'message', 'seen_at'];
}
