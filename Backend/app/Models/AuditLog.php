<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use App\Traits\ExcludesSimulationData;

class AuditLog extends Model
{
    use Tenantable, ExcludesSimulationData;

    protected $guarded = [];
}
