<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

/**
 * Justificante de retardo (Ronda 82). Una fila `approved` para (tenant, user, día) hace que la nómina
 * NO deduzca el retardo de ese día ni lo cuente para faltas fantasma. La emite el empleado y la
 * aprueba un admin (mismo flujo que R56; distinto efecto: perdona la DEDUCCIÓN, no desbloquea la
 * entrada).
 */
class LateJustification extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'date',
        'reason',
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
