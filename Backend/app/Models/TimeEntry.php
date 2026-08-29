<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use App\Traits\ExcludesSimulationData;
use App\Traits\ExcluyeAnuladas;

class TimeEntry extends Model
{
    // El scope de anuladas vive en su propio trait (ExcluyeAnuladas) y NO en un `booted()` de
    // esta clase: un `booted()` aquí pisa el de `Tenantable` y se lleva por delante el
    // TenantScope de la tabla más sensible del producto. El porqué completo, en el trait.
    use Tenantable, ExcludesSimulationData, ExcluyeAnuladas;

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
