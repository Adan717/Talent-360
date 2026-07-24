<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contingencia de jornada por fuerza mayor (Ronda 83). Una fila `approved` para (tenant, user, día)
 * hace que la nómina pague ese día al 100% (jornada causal, LFT Art. 42/427-431) y congele el retardo.
 * La declara el empleado y la aprueba un admin (mismo flujo que R82; distinto efecto: paga el día, no
 * sólo perdona un retardo).
 */
class ContingencyDay extends Model
{
    use Tenantable;

    protected $table = 'contingency_days';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'date',
        'reason',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'resolved_at' => 'datetime',
    ];
}
