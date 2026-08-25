<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use App\Traits\ExcludesSimulationData;

class TimeEntry extends Model
{
    use Tenantable, ExcludesSimulationData;

    /**
     * Un fichaje ANULADO no existe para lo que calcula (2026-08-25). Sigue en la base —se anula,
     * no se borra— pero la nomina, los reportes y el Monitor ven la jornada corregida. Para
     * reconstruir la historia completa: withoutGlobalScope(ExcludeAnuladasScope::class).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Scopes\ExcludeAnuladasScope());
    }

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
        'simulation_session_id',
        // §67: evidencia y método de verificación realmente usado + snapshot del cálculo.
        'photo_url',
        'verification_method',
        'photo_skipped_reason',
        'flagged_for_review',
        'tardiness_minutes_at_time',
        'tolerance_mins_at_time',
        'tolerance_version',
        // Bitacora inmutable: corregir un fichaje lo ANULA, no lo borra.
        'anulado_at',
        'anulado_por_correccion_id',
        'creado_por_correccion_id',
    ];

    protected $casts = [
        'anulado_at' => 'datetime',
    ];
}
