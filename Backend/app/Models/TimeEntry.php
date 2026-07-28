<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use App\Traits\ExcludesSimulationData;

class TimeEntry extends Model
{
    use Tenantable, ExcludesSimulationData;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'date',
        'type',
        'time',
        'is_late',
        'late_minutes',
        'details',
        'employee_name_at_time',
        'job_role_title_at_time',
        'base_salary_at_time',
        'simulation_session_id',
        // §67: evidencia y método de verificación realmente usado + snapshot del cálculo.
        'photo_url',
        'verification_method',
        'photo_skipped_reason',
        'flagged_for_review',
        'tardiness_minutes_at_time',
        'tolerance_mins_at_time',
        'tolerance_version',
    ];
}
