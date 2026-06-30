<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class StoreOpeningEvent extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'employee_id',
        'event_type',
        'event_status',
        'scheduled_opening_time',
        'event_time',
        'estimated_arrival_time',
        'previous_employee_id',
        'next_employee_id',
        'notes',
        'metadata_json',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function previousEmployee()
    {
        return $this->belongsTo(User::class, 'previous_employee_id');
    }

    public function nextEmployee()
    {
        return $this->belongsTo(User::class, 'next_employee_id');
    }
}
