<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud de autorización por retardo (Ronda 56). Una fila `approved` para (tenant, user, día)
 * levanta el bloqueo de Retardo Extremo (R14) en ClockService para ese día.
 */
class LateAuthorizationRequest extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'date',
        'requested_late_minutes',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'requested_late_minutes' => 'integer',
        'resolved_at' => 'datetime',
    ];
}
