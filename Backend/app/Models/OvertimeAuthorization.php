<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

/**
 * Autorización de labor en feriado / día de descanso (Ronda 81). Una fila para (tenant, user, día)
 * levanta el bloqueo de feriado en ClockService::processPunch para ese día. La emite un supervisor
 * validado server-side (QR dinámico de 60s o PIN de kiosko, R54) — nunca el propio empleado (R75).
 */
class OvertimeAuthorization extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'date',
        'kind',
        'authorized_by',
        'method',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];
}
