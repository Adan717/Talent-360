<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

class SimulatorSession extends Model
{
    use Tenantable;

    protected $fillable = ['tenant_id', 'started_by_user_id', 'simulated_date', 'status'];

    protected $casts = [
        'simulated_date' => 'date',
    ];
}
