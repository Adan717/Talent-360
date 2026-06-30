<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class TimeEntry extends Model
{
    use Tenantable;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'date',
        'type',
        'time',
        'is_late',
        'late_minutes',
        'details'
    ];
}
