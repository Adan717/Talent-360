<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class StoreDailyOpeningStatus extends Model
{
    use Tenantable;

    protected $table = 'store_daily_opening_statuses';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'date',
        'scheduled_opening_time',
        'pre_opening_window_start',
        'report_deadline',
        'current_responsible_employee_id',
        'status',
        'opened_by_employee_id',
        'opened_at',
        'failed_at',
    ];

    protected $casts = [
        'date' => 'date',
        'opened_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function currentResponsible()
    {
        return $this->belongsTo(User::class, 'current_responsible_employee_id');
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by_employee_id');
    }
}
