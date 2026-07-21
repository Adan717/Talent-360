<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use App\Traits\ExcludesSimulationData;

class StoreLog extends Model
{
    use Tenantable, ExcludesSimulationData;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'date',
        'type',
        'time',
        'notes',
        'simulation_session_id',
    ];
}
