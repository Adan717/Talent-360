<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class StoreLog extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'date',
        'type',
        'time',
        'notes',
    ];
}
