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
        // Línea del Reloj: Salida Doble Llave (R75) + idempotencia offline (R84).
        'check_out_status',
        'client_stamp',
        // Línea §1–§42: snapshot inmutable + aislamiento del Simulador Matrix.
        'employee_name_at_time',
        'job_role_title_at_time',
        'base_salary_at_time',
        'simulation_session_id'
    ];
}
